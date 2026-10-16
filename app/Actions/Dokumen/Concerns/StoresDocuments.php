<?php

namespace App\Actions\Dokumen\Concerns;

use App\Models\Achievement;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validasi dan penyimpanan bersama `UploadDocument` dan `ReplaceDocument`:
 * pemilik, berkas, nomor, dan tanggal; berkas disimpan ke disk `private`
 * dan dihapus lagi bila penyimpanan baris gagal.
 */
trait StoresDocuments
{
    /**
     * Pemilik harus terdaftar, tidak diarsipkan, dan terlihat oleh cakupan user login.
     */
    protected function ensureOwnerAllowed(Model $owner): string
    {
        $alias = Document::aliasFor($owner);

        if ($alias === null) {
            $this->fail('documentable', 'owner_not_supported');
        }

        if ($owner instanceof Student && $owner->trashed()) {
            $this->fail('documentable', 'owner_archived');
        }

        if (! $this->ownerVisible($owner)) {
            $this->fail('documentable', 'owner_not_found');
        }

        return $alias;
    }

    /**
     * Masukan berkas, nomor, dan tanggal yang sudah dinormalisasi.
     *
     * @param  array<string, mixed>  $data
     * @return array{file: UploadedFile, extension: string, number: ?string, issued_on: ?string, expires_on: ?string}
     */
    protected function validatedFileData(array $data, DocumentType $type): array
    {
        $file = $data['file'] ?? null;

        if (! $file instanceof UploadedFile) {
            $this->fail('file', 'file_required');
        }

        $extension = Document::EXTENSIONS[$file->getMimeType()] ?? null;

        if ($extension === null || (int) $file->getSize() > Document::MAX_SIZE_KB * 1024) {
            $this->fail('file', 'file_invalid');
        }

        $number = trim((string) ($data['number'] ?? ''));

        if (mb_strlen($number) > Document::NUMBER_MAX_LENGTH) {
            $this->fail('number', 'number_too_long');
        }

        $issuedOn = $this->date($data['issued_on'] ?? null, 'issued_on');

        if ($issuedOn !== null && $issuedOn > today()->toDateString()) {
            $this->fail('issued_on', 'issued_on_future');
        }

        $expiresOn = $this->date($data['expires_on'] ?? null, 'expires_on', validate: false);

        if ($expiresOn !== null) {
            if (! $type->has_expiry) {
                $this->fail('expires_on', 'expiry_not_applicable');
            }

            $expiresOn = $this->date($expiresOn, 'expires_on');

            if ($issuedOn !== null && $expiresOn <= $issuedOn) {
                $this->fail('expires_on', 'expires_before_issued');
            }
        }

        return [
            'file' => $file,
            'extension' => $extension,
            'number' => $number === '' ? null : $number,
            'issued_on' => $issuedOn,
            'expires_on' => $expiresOn,
        ];
    }

    /**
     * Simpan berkas, lalu jalankan `$save` dalam transaksi. Bila gagal, berkas dihapus lagi.
     *
     * @param  array{file: UploadedFile, extension: string, number: ?string, issued_on: ?string, expires_on: ?string}  $input
     * @param  Closure(array<string, mixed>): Document  $save
     */
    protected function storeDocument(string $alias, int|string $ownerId, array $input, User $uploader, int $typeId, Closure $save): Document
    {
        $file = $input['file'];
        $path = Storage::disk(Document::DISK)->putFileAs(
            Document::DIRECTORY."/{$alias}/{$ownerId}",
            $file,
            Str::random(40).'.'.$input['extension'],
        );

        if ($path === false) {
            $this->fail('file', 'file_invalid');
        }

        $attributes = [
            'document_type_id' => $typeId,
            'documentable_type' => $alias,
            'documentable_id' => $ownerId,
            'number' => $input['number'],
            'issued_on' => $input['issued_on'],
            'expires_on' => $input['expires_on'],
            'file_path' => $path,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime_type' => $file->getMimeType(),
            'size_kb' => max(1, (int) ceil(((int) $file->getSize()) / 1024)),
            'status' => Document::STATUS_PENDING,
            'uploaded_by' => $uploader->id,
        ];

        try {
            return DB::transaction(fn (): Document => $save($attributes));
        } catch (Throwable $exception) {
            Storage::disk(Document::DISK)->delete($path);

            throw $exception;
        }
    }

    /**
     * Properti log tanpa nomor, path, maupun nama berkas.
     *
     * @return array<string, mixed>
     */
    protected function logProperties(Document $document, DocumentType $type): array
    {
        return [
            'document_type' => $type->code,
            'documentable_type' => $document->documentable_type,
            'documentable_id' => (int) $document->documentable_id,
        ];
    }

    protected function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.dokumen.errors.{$reason}"),
        ]);
    }

    private function ownerVisible(Model $owner): bool
    {
        if ($owner instanceof Student) {
            return Student::query()->withTrashed()->whereKey($owner->getKey())->exists();
        }

        if ($owner instanceof Achievement) {
            return Achievement::query()->whereKey($owner->getKey())->exists();
        }

        if ($owner instanceof Guardian) {
            $user = Auth::user();

            return ! $user instanceof User
                || $user->scopedUnitIds() === []
                || $owner->students()->withTrashed()->exists();
        }

        return true;
    }

    /**
     * Tanggal `Y-m-d` ketat; kosong menjadi null.
     */
    private function date(mixed $value, string $field, bool $validate = true): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! $validate) {
            return is_string($value) ? trim($value) : (string) $value;
        }

        $value = is_string($value) ? trim($value) : '';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $this->fail($field, 'date_invalid');
        }

        return $value;
    }
}
