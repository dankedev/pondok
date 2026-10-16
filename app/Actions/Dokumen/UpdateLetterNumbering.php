<?php

namespace App\Actions\Dokumen;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan pengaturan penomoran surat keluar (`letters.*`). Validasi lewat
 * `LetterNumbering::validate`; kode lembaga kosong disimpan NULL agar kode
 * turunan nama lembaga dipakai. Surat yang sudah terbit tidak berubah.
 */
class UpdateLetterNumbering
{
    /** Kunci settings beserta field masukan. */
    public const SETTING_KEYS = [
        'number_format' => 'letters.number_format',
        'school_code' => 'letters.school_code',
        'type_codes' => 'letters.type_codes',
        'sequence_padding' => 'letters.sequence_padding',
    ];

    public function __construct(private LetterNumbering $letterNumbering) {}

    /**
     * @param  array{number_format?: string|null, school_code?: string|null, type_codes?: array<string, string>|null, sequence_padding?: int|string|null}  $data
     */
    public function handle(array $data, User $actor): void
    {
        $validated = $this->letterNumbering->validate($data);

        DB::transaction(function () use ($validated, $actor): void {
            $old = collect(self::SETTING_KEYS)->map(fn (string $key): mixed => Setting::query()->find($key)?->value)->all();

            foreach (self::SETTING_KEYS as $field => $key) {
                Setting::set($key, $validated[$field]);
            }

            activity('dokumen')
                ->causedBy($actor)
                ->event('numbering_updated')
                ->withProperties(['old' => $old, 'new' => $validated])
                ->log('numbering_updated');
        });
    }
}
