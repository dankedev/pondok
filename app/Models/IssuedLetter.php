<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Database\Factories\IssuedLetterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Surat keluar untuk santri (surat keterangan aktif, pindah, rekomendasi,
 * syahadah, kartu santri), diterbitkan lewat `IssueLetter` dan dibatalkan
 * lewat `CancelIssuedLetter`. Tidak pernah diubah isinya atau dihapus.
 *
 * `snapshot` membekukan isi surat saat terbit dan berisi data pribadi: tidak
 * masuk activity log maupun halaman publik (kecuali nama termasker).
 *
 * Cakupan jenjang dibaca lewat santri (termasuk yang diarsipkan).
 */
#[Fillable(['letter_no', 'type', 'sequence_year', 'sequence', 'student_id', 'signed_by_staff_id', 'issued_on', 'purpose', 'verification_code', 'snapshot', 'file_path', 'status', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'issued_by'])]
class IssuedLetter extends Model
{
    /** @use HasFactory<IssuedLetterFactory> */
    use HasFactory, HasUnitScope;

    public const TYPE_ACTIVE_STATEMENT = 'keterangan_aktif';

    public const TYPE_TRANSFER = 'pindah';

    public const TYPE_RECOMMENDATION = 'rekomendasi';

    public const TYPE_SYAHADAH = 'syahadah';

    public const TYPE_STUDENT_CARD = 'kartu_santri';

    public const TYPES = [
        self::TYPE_ACTIVE_STATEMENT,
        self::TYPE_TRANSFER,
        self::TYPE_RECOMMENDATION,
        self::TYPE_SYAHADAH,
        self::TYPE_STUDENT_CARD,
    ];

    /** Jenis yang wajib mencantumkan keperluan. */
    public const TYPES_REQUIRING_PURPOSE = [
        self::TYPE_ACTIVE_STATEMENT,
        self::TYPE_TRANSFER,
        self::TYPE_RECOMMENDATION,
        self::TYPE_SYAHADAH,
    ];

    /** Status santri yang boleh menerima tiap jenis surat. */
    public const ALLOWED_STUDENT_STATUSES = [
        self::TYPE_ACTIVE_STATEMENT => ['aktif'],
        self::TYPE_TRANSFER => ['aktif', 'pindah'],
        self::TYPE_RECOMMENDATION => ['aktif', 'lulus'],
        self::TYPE_SYAHADAH => ['aktif', 'lulus'],
        self::TYPE_STUDENT_CARD => ['aktif'],
    ];

    public const STATUS_ISSUED = 'terbit';

    public const STATUS_CANCELLED = 'batal';

    public const STATUSES = [
        self::STATUS_ISSUED,
        self::STATUS_CANCELLED,
    ];

    /** Warna badge sesuai design guide §4. */
    public const STATUS_COLORS = [
        self::STATUS_ISSUED => 'success',
        self::STATUS_CANCELLED => 'danger',
    ];

    /** Alfabet kode verifikasi tanpa karakter mirip (0/1/I/L/O). */
    public const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const CODE_LENGTH = 12;

    public const PURPOSE_MAX_LENGTH = 255;

    public const REASON_MAX_LENGTH = 255;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ISSUED,
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'snapshot' => 'array',
            'cancelled_at' => 'datetime',
            'sequence' => 'integer',
            'sequence_year' => 'integer',
        ];
    }

    /**
     * @param  list<int>  $unitIds
     */
    public function applyUnitScope(Builder $builder, array $unitIds): void
    {
        $builder->whereIn(
            $this->qualifyColumn('student_id'),
            Student::withoutGlobalScopes()->whereIn('unit_id', $unitIds)->select('id'),
        );
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'signed_by_staff_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function typeLabel(): string
    {
        return static::typeLabelFor((string) $this->type);
    }

    public static function typeLabelFor(string $type): string
    {
        return __("pondok.issued_letter.types.{$type}");
    }

    public function statusLabel(): string
    {
        return __("pondok.issued_letter.statuses.{$this->status}");
    }

    /**
     * Kode acak `CODE_LENGTH` karakter dari `CODE_ALPHABET`.
     */
    public static function newVerificationCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Huruf besar, buang selain huruf dan angka (spasi, strip).
     */
    public static function normalizeVerificationCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    /**
     * Kode berkelompok empat, misalnya `ABCD-EFGH-JKMN`.
     */
    public function formattedVerificationCode(): string
    {
        return implode('-', str_split((string) $this->verification_code, 4));
    }

    /**
     * URL absolut halaman verifikasi publik (berbasis `app.url`, juga dipakai QR).
     */
    public function verificationUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('dokumen.letters.verify', ['code' => $this->verification_code], absolute: false);
    }

    /**
     * Penandatangan beku berurutan `[{label, name, position, nip}]`. Surat lama hanya punya
     * `snapshot.signer` tunggal (tanpa label); tanpa keduanya hasilnya kosong.
     *
     * @return list<array{label?: string|null, name?: string|null, position?: string|null, nip?: string|null}>
     */
    public function signerList(): array
    {
        $snapshot = (array) $this->snapshot;

        if (is_array($snapshot['signers'] ?? null)) {
            return array_values($snapshot['signers']);
        }

        return is_array($snapshot['signer'] ?? null) ? [['label' => null, ...$snapshot['signer']]] : [];
    }

    /**
     * Nama-nama penandatangan digabung koma.
     */
    public function signerNames(): ?string
    {
        return collect($this->signerList())->pluck('name')->filter()->implode(', ') ?: null;
    }

    /**
     * Nama santri dari snapshot dengan kata kedua dan seterusnya dijadikan inisial.
     */
    public function maskedStudentName(): string
    {
        return static::maskName((string) ($this->snapshot['student']['name'] ?? ''));
    }

    /**
     * `Muhammad Fauzan Akbar` menjadi `Muhammad F. A.`; satu kata tetap utuh.
     */
    public static function maskName(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        $first = array_shift($words);

        return trim($first.' '.implode(' ', array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)).'.', $words)));
    }
}
