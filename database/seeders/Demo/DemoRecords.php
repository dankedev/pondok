<?php

namespace Database\Seeders\Demo;

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Actions\Dokumen\IssueLetter;
use App\Actions\Dokumen\ReplaceDocument;
use App\Actions\Dokumen\UploadDocument;
use App\Actions\Dokumen\VerifyDocument;
use App\Actions\Konten\SavePost;
use App\Actions\Prestasi\PublishAchievementAsPost;
use App\Actions\Prestasi\SaveAchievement;
use App\Actions\Prestasi\VerifyAchievement;
use App\Models\Achievement;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\IssuedLetter;
use App\Models\Post;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

/**
 * Dokumen santri, surat keluar, dan prestasi demo.
 */
final class DemoRecords
{
    /** Surat keluar: [indeks santri aktif, jenis, keperluan, batal]. */
    private const LETTERS = [
        [30, 'keterangan_aktif', 'Persyaratan pengajuan beasiswa', false],
        [31, 'keterangan_aktif', 'Pendaftaran BPJS', false],
        [32, 'rekomendasi', 'Pendaftaran lomba tingkat provinsi', false],
        [33, 'kartu_santri', null, false],
        [34, 'keterangan_aktif', 'Pengajuan beasiswa daerah', true],
    ];

    public const LETTER_CANCEL_REASON = 'Salah keperluan';

    /** @var array<string, DocumentType> */
    private array $types = [];

    public function __construct(private readonly DemoContext $context) {}

    public function run(): void
    {
        $this->types = DocumentType::query()->get()->keyBy('code')->all();
        $active = array_values(array_filter(DemoData::students(), fn (array $row): bool => $row['a'] !== null));
        $students = array_map(fn (array $row): Student => $this->context->student($row['name']), $active);

        $this->seedDocuments($students);
        $this->seedLetters($students);
        $this->seedAchievements();
    }

    /**
     * @param  list<Student>  $students
     */
    private function seedDocuments(array $students): void
    {
        $verifier = $this->context->user('admin-sekolah');

        foreach ($students as $index => $student) {
            if ($index < 20) {
                $this->document($student, 'KK', Document::STATUS_VALID, ['number' => '1608'.str_pad((string) (40021 + $index * 37), 12, '0', STR_PAD_LEFT)]);
                $this->document($student, 'AKTA', Document::STATUS_VALID);
            } elseif ($index < 26) {
                $this->document($student, 'KK', null, ['number' => '1608'.str_pad((string) (40021 + $index * 37), 12, '0', STR_PAD_LEFT)]);
            }
        }

        $this->document($students[20], 'AKTA', Document::STATUS_REJECTED);
        $this->document($students[26], 'BPJS', Document::STATUS_VALID, [
            'number' => '0001'.str_pad('26', 9, '0', STR_PAD_LEFT),
            'expires_on' => $this->context->calendar->today->addDays(20)->toDateString(),
        ]);

        $replaced = Document::withoutUnitScope()
            ->where('documentable_type', $students[0]->getMorphClass())
            ->where('documentable_id', $students[0]->id)
            ->whereNotNull('replaced_by_id')
            ->exists();

        if (! $replaced) {
            $current = $this->currentDocument($students[0], 'KK');
            app(ReplaceDocument::class)->handle($current, [
                'file' => $this->context->files->pdf('kk-baru'),
                'number' => '1608'.str_pad('40021', 12, '0', STR_PAD_LEFT),
            ], $verifier);
        }
    }

    private function currentDocument(Model $owner, string $code): ?Document
    {
        return Document::withoutUnitScope()
            ->where('documentable_type', $owner->getMorphClass())
            ->where('documentable_id', $owner->getKey())
            ->where('document_type_id', $this->types[$code]->id)
            ->whereNull('replaced_by_id')
            ->first();
    }

    /**
     * Unggah satu dokumen bila pemilik belum punya dokumen jenis itu, lalu verifikasi.
     *
     * @param  array<string, string>  $extra
     */
    private function document(Model $owner, string $code, ?string $status, array $extra = []): void
    {
        if ($this->currentDocument($owner, $code) !== null) {
            return;
        }

        $user = $this->context->user($owner instanceof Achievement ? 'kesantrian' : 'admin-sekolah');
        $document = app(UploadDocument::class)->handle($owner, [
            'document_type_id' => $this->types[$code]->id,
            'file' => $this->context->files->pdf(strtolower($code)),
            ...$extra,
        ], $user);

        if ($status !== null) {
            app(VerifyDocument::class)->handle($document, $status, $user);
        }
    }

    /**
     * @param  list<Student>  $students
     */
    private function seedLetters(array $students): void
    {
        $issuer = $this->context->user('admin-sekolah');

        foreach (self::LETTERS as [$index, $type, $purpose, $cancel]) {
            $student = $students[$index];
            $exists = IssuedLetter::withoutGlobalScopes()
                ->where('student_id', $student->id)
                ->where('type', $type)
                ->when($purpose === null, fn ($query) => $query->whereNull('purpose'), fn ($query) => $query->where('purpose', $purpose))
                ->exists();

            if ($exists) {
                continue;
            }

            $letter = app(IssueLetter::class)->handle($student, [
                'type' => $type,
                'signed_by_staff_id' => $this->context->mudir()->id,
                'purpose' => $purpose,
            ], $issuer);

            if ($cancel) {
                app(CancelIssuedLetter::class)->handle($letter, $issuer, self::LETTER_CANCEL_REASON);
            }
        }
    }

    private function seedAchievements(): void
    {
        $actor = $this->context->user('kesantrian');

        foreach (DemoData::ACHIEVEMENTS as $row) {
            $achievedOn = $this->context->calendar->daysAgo($row['offset'])->toDateString();
            $achievement = Achievement::withoutUnitScope()->where('title', $row['title'])->whereDate('achieved_on', $achievedOn)->first();

            $achievement ??= app(SaveAchievement::class)->handle([
                'category' => $row['category'],
                'title' => $row['title'],
                'event_name' => $row['event'],
                'organizer' => $row['organizer'],
                'level' => $row['level'],
                'kind' => $row['kind'],
                'achieved_on' => $achievedOn,
                'is_public' => $row['public'],
                'participants' => array_map(fn (array $participant): array => [
                    'student_id' => $this->context->student($participant[0])->id,
                    'rank' => $participant[1],
                    'role' => $participant[2],
                ], $row['participants']),
            ], $actor);

            if ($row['verified'] && $achievement->verified_by === null) {
                app(VerifyAchievement::class)->handle($achievement, $actor);
            }

            if ($row['certificate'] ?? false) {
                $this->document($achievement, 'SERTIFIKAT', null);
            }

            if (($row['post'] ?? false) && ! Post::withTrashed()->where('achievement_id', $achievement->id)->exists()) {
                $this->publishPost($achievement->refresh());
            }
        }
    }

    private function publishPost(Achievement $achievement): void
    {
        $actor = $this->context->user('admin-konten');
        $post = app(PublishAchievementAsPost::class)->handle($achievement, $actor);

        app(SavePost::class)->handle([
            'type' => $post->type,
            'title' => $post->title,
            'slug' => $post->slug,
            'category_id' => Category::query()->where('type', Category::TYPE_POST)->where('name', DemoData::ACHIEVEMENT_CATEGORY)->value('id'),
            'unit_id' => $post->unit_id,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => $this->context->calendar->daysAgo(18)->setTime(9, 0)->format('Y-m-d H:i:s'),
        ], $actor, $post);
    }
}
