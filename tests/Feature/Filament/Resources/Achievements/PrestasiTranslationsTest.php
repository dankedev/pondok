<?php

use App\Models\Achievement;
use Illuminate\Support\Facades\File;

/**
 * Pastikan kunci lang ada (hasil terjemahan bukan kunci mentah) dan tidak kosong.
 */
function prestasiAssertTranslated(string $key, array $replace = []): void
{
    $value = __($key, $replace);

    expect($value)->toBeString()->not->toBe($key, "Kunci lang {$key} belum ada")->not->toBe('');
}

it('AC-39: translates every prestasi message', function () {
    $reasons = [
        'outside_scope', 'category_invalid', 'title_required', 'too_long', 'event_name_required', 'level_invalid', 'kind_invalid',
        'date_invalid', 'achieved_on_future', 'academic_year_not_found', 'participants_required', 'participants_too_many',
        'participant_duplicate', 'individual_single', 'team_too_small', 'student_not_found', 'student_archived', 'rank_required',
        'public_post_exists', 'already_verified', 'verified_locked', 'has_post', 'has_documents', 'not_public', 'not_verified',
        'already_published', 'not_publishable',
    ];

    foreach ($reasons as $reason) {
        prestasiAssertTranslated("pondok.prestasi.errors.{$reason}");
    }

    expect(__('pondok.navigation.prestasi'))->toBe('Prestasi')
        ->and(__('pondok.document.owner_types.achievement'))->toBe('Prestasi');
});

it('AC-39: translates every category, level, kind and verification status', function () {
    foreach (Achievement::CATEGORIES as $category) {
        prestasiAssertTranslated("pondok.achievement.categories.{$category}");
    }

    foreach (Achievement::LEVELS as $level) {
        prestasiAssertTranslated("pondok.achievement.levels.{$level}");
    }

    foreach (Achievement::KINDS as $kind) {
        prestasiAssertTranslated("pondok.achievement.kinds.{$kind}");
    }

    foreach (array_keys(Achievement::VERIFICATION_COLORS) as $status) {
        prestasiAssertTranslated("pondok.achievement.verification_statuses.{$status}");
    }

    expect(__('pondok.achievement.levels.kabupaten'))->toBe('Kabupaten')
        ->and(__('pondok.achievement.levels.pondok'))->toBe('Pondok')
        ->and(__('pondok.achievement.levels.nasional'))->toBe('Nasional')
        ->and(__('pondok.achievement.levels.provinsi'))->toBe('Provinsi');
});

it('AC-39: translates the labels, helpers and texts of the prestasi screens', function () {
    $keys = [
        'achievement.label', 'achievement.plural', 'achievement.title', 'achievement.event_name', 'achievement.organizer',
        'achievement.category', 'achievement.level', 'achievement.kind', 'achievement.achieved_on', 'achievement.achieved_on_helper',
        'achievement.academic_year', 'achievement.is_public', 'achievement.is_public_helper', 'achievement.participants',
        'achievement.participant_student', 'achievement.participant_rank', 'achievement.participant_role', 'achievement.add_participant',
        'achievement.archived_suffix', 'achievement.recorded_by', 'achievement.verified_by', 'achievement.verification_filter',
        'achievement.post', 'achievement.section_achievement', 'achievement.section_participants', 'achievement.edit_resets_verification',
        'achievement.verify_action', 'achievement.verify_description', 'achievement.publish_action', 'achievement.publish_description',
        'achievement.from_achievement_action', 'achievement.from_achievement_empty', 'achievement.post_intro', 'achievement.delete_action',
        'achievement.delete_description', 'achievement.create_action', 'achievement.empty', 'achievement.student_filter',
        'achievement.view_achievements',
        'achievement_recap.title', 'achievement_recap.navigation', 'achievement_recap.summary', 'achievement_recap.total',
        'achievement_recap.participations', 'achievement_recap.matrix_total', 'achievement_recap.students', 'achievement_recap.achievement_count',
        'achievement_recap.highest_level', 'achievement_recap.all_years', 'achievement_recap.include_unverified',
        'achievement_recap.verified_only_note', 'achievement_recap.including_unverified_note', 'achievement_recap.empty',
        'achievement_student_tab.title', 'achievement_student_tab.empty',
        'dashboard.achievements.empty', 'dashboard.achievements.list_link',
    ];

    foreach ($keys as $key) {
        prestasiAssertTranslated("pondok.{$key}");
    }

    prestasiAssertTranslated('pondok.achievement.others', ['count' => 2]);
    prestasiAssertTranslated('pondok.achievement.verified', ['title' => 'X']);
    prestasiAssertTranslated('pondok.achievement.published', ['title' => 'X']);
    prestasiAssertTranslated('pondok.achievement.post_excerpt', ['event' => 'A', 'level' => 'B', 'date' => 'C']);
    prestasiAssertTranslated('pondok.achievement.post_participant');
    prestasiAssertTranslated('pondok.achievement_student_tab.summary', ['total' => 1, 'level' => 'Pondok']);
    prestasiAssertTranslated('pondok.achievement_student_tab.unverified_note', ['count' => 1]);
});

it('AC-39: uses the Indonesian wording from the plan for the visible notes', function () {
    expect(__('pondok.achievement_recap.verified_only_note'))->toBe('Hanya prestasi terverifikasi.')
        ->and(__('pondok.achievement_recap.including_unverified_note'))->toBe('Termasuk prestasi yang belum diverifikasi.')
        ->and(__('pondok.achievement.empty'))->toBe('Belum ada prestasi untuk tahun ajaran ini.')
        ->and(__('pondok.achievement.create_action'))->toBe('Catat prestasi')
        ->and(__('pondok.dashboard.achievements.empty'))->toBe('Belum ada prestasi terverifikasi.')
        ->and(__('pondok.achievement.edit_resets_verification'))->toBe('Mengubah isi prestasi membatalkan verifikasi.');
});

it('AC-39: keeps raw SQL away from the rank, role and level columns in prestasi actions', function () {
    $directory = app_path('Actions/Prestasi');

    expect(File::isDirectory($directory))->toBeTrue('Folder app/Actions/Prestasi belum ada');

    foreach (File::files($directory) as $file) {
        preg_match_all('/(?:selectRaw|orderByRaw|whereRaw|havingRaw|groupByRaw|DB::raw)\s*\(([^;]*)/', $file->getContents(), $matches);

        foreach ($matches[1] as $fragment) {
            expect($fragment)->not->toMatch('/\b(rank|role|level)\b/i', "{$file->getFilename()} memakai SQL mentah untuk kolom terpesan");
        }
    }
});
