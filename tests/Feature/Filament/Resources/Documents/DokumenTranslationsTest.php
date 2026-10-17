<?php

use App\Actions\Dokumen\ReplaceDocument;
use App\Actions\Dokumen\SaveDocumentType;
use App\Actions\Dokumen\UploadDocument;
use App\Actions\Dokumen\VerifyDocument;

/**
 * Semua alasan penolakan Action dokumen (rencana F013, bagian teks).
 *
 * @var list<string>
 */
const DOKUMEN_ERROR_REASONS = [
    'code_format', 'code_taken', 'code_locked', 'name_required', 'owner_invalid', 'owner_locked', 'required_only_for_student',
    'owner_not_supported', 'owner_archived', 'owner_not_found', 'document_type_not_found', 'document_type_inactive',
    'document_type_owner_mismatch', 'file_required', 'file_invalid', 'number_too_long', 'date_invalid', 'issued_on_future',
    'expiry_not_applicable', 'expires_before_issued', 'already_replaced', 'status_invalid', 'document_replaced',
    'status_unchanged', 'document_expired', 'confidential_not_allowed',
];

it('AC-41: translates every dokumen message', function () {
    expectTranslatedKeys(collect(DOKUMEN_ERROR_REASONS)->map(fn (string $reason): string => "pondok.dokumen.errors.{$reason}"));
});

it('AC-41: gives every message a way forward', function () {
    $messages = collect(DOKUMEN_ERROR_REASONS)->mapWithKeys(fn (string $reason): array => [$reason => __("pondok.dokumen.errors.{$reason}")]);

    expect($messages->unique()->count())->toBe($messages->count())
        ->and($messages['file_invalid'])->toContain('JPG')->toContain('PNG')->toContain('PDF')->toContain('2 MB')
        ->and($messages->every(fn (string $message): bool => str_ends_with($message, '.')))->toBeTrue();
});

it('AC-41: names the statuses and the menu group', function () {
    expect(__('pondok.document.statuses.menunggu'))->toBe('Menunggu')
        ->and(__('pondok.document.statuses.valid'))->toBe('Valid')
        ->and(__('pondok.document.statuses.ditolak'))->toBe('Ditolak')
        ->and(__('pondok.document.statuses.diganti'))->toBe('Diganti')
        ->and(__('pondok.navigation.dokumen'))->toBe('Dokumen');
});

it('AC-41: defines every UI text the plan lists', function () {
    $typeKeys = ['label', 'plural', 'code', 'code_helper', 'name', 'owner', 'is_required', 'is_required_helper', 'has_expiry', 'is_confidential',
        'is_confidential_helper', 'is_active', 'documents_count', 'empty', 'delete_description',
        'owners.santri', 'owners.wali', 'owners.staff', 'owners.prestasi', 'required_labels.1', 'required_labels.0'];
    $documentKeys = ['label', 'plural', 'document_type', 'owner', 'number', 'number_helper', 'issued_on', 'expires_on', 'file', 'original_name',
        'mime_type', 'size_kb', 'status', 'uploaded_by', 'uploaded_at', 'verified_by', 'verified_at', 'confidential', 'current_only',
        'expired_filter', 'completeness_filter', 'complete', 'incomplete', 'upload_action', 'uploaded', 'replace_action', 'replace_description',
        'replaced', 'verify_action', 'verify_description', 'verified', 'download_action', 'view_action', 'empty', 'empty_pending',
        'activity_uploaded', 'activity_replaced', 'activity_verified',
        'owner_types.student', 'owner_types.guardian', 'owner_types.staff', 'statuses.menunggu', 'statuses.valid', 'statuses.ditolak', 'statuses.diganti',
        'completeness_labels.1', 'completeness_labels.0', 'completeness_reasons.missing', 'completeness_reasons.pending',
        'completeness_reasons.expired', 'completeness_reasons.rejected'];

    expectTranslatedKeys(collect($typeKeys)->map(fn (string $key): string => "pondok.document_type.{$key}")
        ->merge(collect($documentKeys)->map(fn (string $key): string => "pondok.document.{$key}")));
});

it('AC-41: knows every action class the messages belong to', function () {
    foreach ([SaveDocumentType::class, UploadDocument::class, ReplaceDocument::class, VerifyDocument::class] as $class) {
        expect(class_exists($class))->toBeTrue("{$class} belum ada");
    }
});
