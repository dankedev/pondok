<?php

/**
 * Alasan penolakan Action surat keluar (rencana F014, bagian teks).
 *
 * @var list<string>
 */
const SURAT_ERROR_REASONS = [
    'letter_type_invalid', 'student_archived', 'student_not_found', 'student_status_not_allowed', 'signer_invalid',
    'purpose_required', 'purpose_too_long', 'number_conflict', 'reason_invalid', 'already_cancelled', 'format_token_unknown',
    'format_token_missing', 'format_character_invalid', 'format_too_long', 'code_invalid', 'type_codes_not_unique',
    'padding_invalid', 'type_code_locked',
];

/**
 * Kunci tampilan surat keluar (rencana F014, bagian teks).
 *
 * @var list<string>
 */
const SURAT_UI_KEYS = [
    'issued_letter.label', 'issued_letter.plural', 'issued_letter.letter_no', 'issued_letter.type', 'issued_letter.student',
    'issued_letter.issued_on', 'issued_letter.purpose', 'issued_letter.signer', 'issued_letter.status',
    'issued_letter.verification_code', 'issued_letter.verification_url', 'issued_letter.issued_by', 'issued_letter.cancelled_at',
    'issued_letter.cancelled_by', 'issued_letter.cancel_reason', 'issued_letter.year', 'issued_letter.issue',
    'issued_letter.issue_hint_date', 'issued_letter.issued', 'issued_letter.print', 'issued_letter.cancel',
    'issued_letter.cancel_description', 'issued_letter.cancel_reason_label', 'issued_letter.cancelled', 'issued_letter.empty',
    'issued_letter.view',
    'letter_numbering.title', 'letter_numbering.format', 'letter_numbering.format_help', 'letter_numbering.school_code',
    'letter_numbering.school_code_help', 'letter_numbering.type_codes', 'letter_numbering.sequence_padding',
    'letter_numbering.preview', 'letter_numbering.saved',
    'letter.number', 'letter.place_date', 'letter.signature_space', 'letter.scan_hint', 'letter.issued_by', 'letter.printed_at',
    'letter.cancelled', 'letter.cancelled_on', 'letter.cancelled_reason', 'letter.print_button', 'letter.card_title',
    'letter.photo_frame', 'letter.nip', 'letter.qr_label', 'letter.phone', 'letter.classroom', 'letter.academic_year',
    'letter_verification.title', 'letter_verification.form_title', 'letter_verification.code_label', 'letter_verification.submit',
    'letter_verification.code_required', 'letter_verification.valid_title', 'letter_verification.cancelled_title',
    'letter_verification.not_found', 'letter_verification.school', 'letter_verification.letter_no', 'letter_verification.type',
    'letter_verification.issued_on', 'letter_verification.student', 'letter_verification.signer',
    'letter_verification.student_status', 'letter_verification.student_active', 'letter_verification.student_inactive',
    'letter_verification.cancelled_on',
];

const SURAT_LETTER_TYPES = ['keterangan_aktif', 'pindah', 'rekomendasi', 'syahadah', 'kartu_santri'];

it('AC-39: translates every letter message', function () {
    expectTranslatedKeys(collect(SURAT_ERROR_REASONS)->map(fn (string $reason): string => "pondok.dokumen.errors.{$reason}"));
});

it('AC-39: gives every letter message a way forward', function () {
    $messages = collect(SURAT_ERROR_REASONS)->mapWithKeys(fn (string $reason): array => [$reason => __("pondok.dokumen.errors.{$reason}")]);

    expect($messages->unique()->count())->toBe($messages->count())
        ->and($messages->every(fn (string $message): bool => str_ends_with($message, '.')))->toBeTrue()
        ->and($messages['number_conflict'])->toBe('Nomor surat bentrok dengan surat lain. Periksa pengaturan kode jenis surat lalu coba lagi.');
});

it('AC-39: defines every letter label', function () {
    expectTranslatedKeys(collect(SURAT_UI_KEYS)->map(fn (string $key): string => "pondok.{$key}"));
});

it('AC-39: names every letter type and print title', function (string $type) {
    expect(__("pondok.issued_letter.types.{$type}"))->not->toBe("pondok.issued_letter.types.{$type}")->not->toBe('');

    if ($type !== 'kartu_santri') {
        expect(__("pondok.letter.titles.{$type}"))->not->toBe("pondok.letter.titles.{$type}")->not->toBe('')
            ->and(__("pondok.letter.bodies.{$type}"))->not->toBe("pondok.letter.bodies.{$type}")->not->toBe('');
    }
})->with(SURAT_LETTER_TYPES);

it('AC-39: names the letter statuses', function () {
    expect(__('pondok.issued_letter.statuses.terbit'))->toBe('Terbit')
        ->and(__('pondok.issued_letter.statuses.batal'))->toBe('Batal');
});

it('AC-39: words the public verification messages as planned', function () {
    expect(__('pondok.letter_verification.valid_title'))->toBe('Surat asli dan berlaku')
        ->and(__('pondok.letter_verification.cancelled_title'))->toBe('Surat ini telah dibatalkan')
        ->and(__('pondok.letter_verification.not_found'))->toBe('Kode verifikasi tidak ditemukan. Periksa kembali kode yang tercetak di surat.')
        ->and(__('pondok.letter_verification.code_required'))->toBe('Masukkan kode verifikasi yang tercetak di surat.')
        ->and(__('pondok.letter_verification.student_active'))->toBe('Aktif')
        ->and(__('pondok.letter_verification.student_inactive'))->toBe('Tidak aktif')
        ->and(__('pondok.letter.cancelled'))->toBe('DIBATALKAN')
        ->and(__('pondok.issued_letter.empty'))->toBe('Belum ada surat keluar.');
});

it('AC-39: keeps the print titles apart', function () {
    $titles = collect(['keterangan_aktif', 'pindah', 'rekomendasi', 'syahadah'])->map(fn (string $type): string => __("pondok.letter.titles.{$type}"));

    expect($titles->unique()->count())->toBe(4)
        ->and($titles->contains(fn (string $title): bool => str_starts_with($title, 'pondok.')))->toBeFalse();
});
