<?php

namespace App\Actions\Prestasi;

use App\Actions\Prestasi\Concerns\FailsWithPrestasiErrors;
use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\Post;
use App\Models\Student;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat atau mengubah prestasi beserta pesertanya.
 *
 * Validasi berhenti di galat pertama dan tidak menulis apa pun. Tahun ajaran
 * diturunkan dari `achieved_on` setiap simpan. Santri dicari dengan cakupan
 * jenjang user login (termasuk terarsip); santri terarsip hanya boleh bila
 * sudah menjadi peserta prestasi ini. Perubahan substantif pada prestasi
 * terverifikasi membatalkan verifikasi; perubahan `is_public` saja tidak.
 */
class SaveAchievement
{
    use FailsWithPrestasiErrors;

    public const TEXT_MAX_LENGTH = 255;

    /** Kolom yang perubahannya membatalkan verifikasi (selain peserta). */
    private const SUBSTANTIVE = ['category', 'title', 'event_name', 'organizer', 'level', 'kind', 'achieved_on'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?Achievement $achievement = null): Achievement
    {
        if ($achievement !== null) {
            $this->ensureFullyVisible($achievement);
        }

        $category = $this->choice($data['category'] ?? null, Achievement::CATEGORIES, 'category', 'category_invalid');
        $title = $this->requiredText($data['title'] ?? null, 'title', 'title_required');
        $eventName = $this->requiredText($data['event_name'] ?? null, 'event_name', 'event_name_required');
        $organizer = $this->optionalText($data['organizer'] ?? null, 'organizer', self::TEXT_MAX_LENGTH);
        $level = $this->choice($data['level'] ?? null, Achievement::LEVELS, 'level', 'level_invalid');
        $kind = $this->choice($data['kind'] ?? null, Achievement::KINDS, 'kind', 'kind_invalid');
        $achievedOn = $this->achievedOn($data['achieved_on'] ?? null);
        $academicYearId = $this->academicYearId($achievedOn);
        $participants = $this->participants($data['participants'] ?? null, $kind, $achievement);
        $isPublic = (bool) ($data['is_public'] ?? false);

        if ($achievement !== null && $achievement->is_public && ! $isPublic && $this->hasPublishedPost($achievement)) {
            $this->fail('is_public', 'public_post_exists');
        }

        $attributes = [
            'academic_year_id' => $academicYearId,
            'category' => $category,
            'title' => $title,
            'event_name' => $eventName,
            'organizer' => $organizer,
            'level' => $level,
            'kind' => $kind,
            'achieved_on' => $achievedOn,
            'is_public' => $isPublic,
        ];

        return DB::transaction(function () use ($achievement, $actor, $attributes, $participants): Achievement {
            if ($achievement === null) {
                $achievement = new Achievement;
                $achievement->forceFill([...$attributes, 'recorded_by' => $actor->id, 'verified_by' => null])->save();
                $achievement->students()->attach($participants);
            } else {
                $substantive = $this->changesSubstance($achievement, $attributes, $participants);
                $achievement->forceFill([
                    ...$attributes,
                    'verified_by' => $substantive ? null : $achievement->verified_by,
                ])->save();
                $achievement->students()->sync($participants);
            }

            return Achievement::withoutUnitScope()->with('students')->findOrFail($achievement->id);
        });
    }

    /**
     * @param  list<string>  $allowed
     */
    private function choice(mixed $value, array $allowed, string $field, string $reason): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $this->fail($field, $reason);
        }

        return $value;
    }

    private function requiredText(mixed $value, string $field, string $reason): string
    {
        $text = $this->optionalText($value, $field, self::TEXT_MAX_LENGTH);

        if ($text === null) {
            $this->fail($field, $reason);
        }

        return $text;
    }

    private function optionalText(mixed $value, string $field, int $max): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            $this->fail($field, 'too_long', ['max' => $max]);
        }

        return $text;
    }

    private function achievedOn(mixed $value): string
    {
        $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (is_string($value) ? trim($value) : '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $this->fail('achieved_on', 'date_invalid');
        }

        if ($value > today()->toDateString()) {
            $this->fail('achieved_on', 'achieved_on_future');
        }

        return $value;
    }

    /**
     * Tahun ajaran yang mencakup tanggal (kedua batas inklusif); bila tumpang tindih, `starts_on` terbaru.
     */
    private function academicYearId(string $date): int
    {
        $id = AcademicYear::query()
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->value('id');

        if ($id === null) {
            $this->fail('achieved_on', 'academic_year_not_found');
        }

        return (int) $id;
    }

    /**
     * @return array<int, array{rank: string, role: ?string}>
     */
    private function participants(mixed $value, string $kind, ?Achievement $achievement): array
    {
        $rows = is_array($value) ? array_values(array_filter($value, 'is_array')) : [];

        if ($rows === []) {
            $this->fail('participants', 'participants_required');
        }

        if (count($rows) > Achievement::MAX_PARTICIPANTS) {
            $this->fail('participants', 'participants_too_many', ['max' => Achievement::MAX_PARTICIPANTS]);
        }

        $ids = array_map(fn (array $row): ?int => is_numeric($row['student_id'] ?? null) ? (int) $row['student_id'] : null, $rows);

        if (count(array_filter($ids, fn (?int $id): bool => $id !== null)) !== count(array_unique(array_filter($ids, fn (?int $id): bool => $id !== null)))) {
            $this->fail('participants', 'participant_duplicate');
        }

        if ($kind === Achievement::KIND_INDIVIDUAL && count($rows) !== 1) {
            $this->fail('participants', 'individual_single');
        }

        if ($kind === Achievement::KIND_TEAM && count($rows) < 2) {
            $this->fail('participants', 'team_too_small');
        }

        $students = Student::query()->withTrashed()->whereKey(array_filter($ids))->get()->keyBy('id');
        $existing = $achievement === null ? [] : DB::table('achievement_student')
            ->where('achievement_id', $achievement->id)
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($ids as $id) {
            $student = $id === null ? null : $students->get($id);

            if ($student === null) {
                $this->fail('participants', 'student_not_found');
            }

            if ($student->trashed() && ! in_array($id, $existing, true)) {
                $this->fail('participants', 'student_archived');
            }
        }

        $participants = [];

        foreach ($rows as $index => $row) {
            $rank = $this->optionalText($row['rank'] ?? null, 'participants', Achievement::RANK_MAX_LENGTH);

            if ($rank === null) {
                $this->fail('participants', 'rank_required');
            }

            $participants[$ids[$index]] = [
                'rank' => $rank,
                'role' => $this->optionalText($row['role'] ?? null, 'participants', Achievement::ROLE_MAX_LENGTH),
            ];
        }

        return $participants;
    }

    /**
     * Berita tidak terhapus yang berstatus terbit.
     */
    private function hasPublishedPost(Achievement $achievement): bool
    {
        return Post::query()
            ->where('achievement_id', $achievement->id)
            ->where('status', Post::STATUS_PUBLISHED)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{rank: string, role: ?string}>  $participants
     */
    private function changesSubstance(Achievement $achievement, array $attributes, array $participants): bool
    {
        $current = [
            ...$achievement->only(self::SUBSTANTIVE),
            'achieved_on' => $achievement->achieved_on?->toDateString(),
        ];

        foreach (self::SUBSTANTIVE as $column) {
            if ($current[$column] !== $attributes[$column]) {
                return true;
            }
        }

        $existing = DB::table('achievement_student')
            ->where('achievement_id', $achievement->id)
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->student_id => ['rank' => (string) $row->rank, 'role' => $row->role]])
            ->all();

        ksort($existing);
        ksort($participants);

        return $existing !== $participants;
    }
}
