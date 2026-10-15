<?php

namespace App\Actions\Keuangan;

use App\Models\FeeType;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah jenis biaya.
 *
 * `code` dan `billing_group` dinormalisasi ke huruf besar. Jenis biaya yang
 * sudah punya tarif ber-asrama tidak boleh diubah menjadi khusus nonmukim.
 */
class SaveFeeType
{
    private const CODE_PATTERN = '/^[A-Z0-9_]{1,30}$/';

    private const MAX_PRIORITY = 65535;

    /**
     * @param  array{code: string, name: string, frequency: string, applies_to?: string, billing_group: string, priority?: int|string|null, is_active?: bool}  $data
     */
    public function handle(array $data, ?FeeType $feeType = null): FeeType
    {
        $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
        $billingGroup = str_replace(' ', '_', mb_strtoupper(trim((string) ($data['billing_group'] ?? ''))));
        $appliesTo = $data['applies_to'] ?? FeeType::APPLIES_TO_ALL;
        $priority = $data['priority'] ?? null;
        $priority = blank($priority) ? FeeType::DEFAULT_PRIORITY : filter_var($priority, FILTER_VALIDATE_INT);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            $this->fail('code', 'code_format');
        }

        if (preg_match(self::CODE_PATTERN, $billingGroup) !== 1) {
            $this->fail('billing_group', 'billing_group_format');
        }

        $codeTaken = FeeType::query()
            ->where('code', $code)
            ->when($feeType, fn ($query, FeeType $feeType) => $query->whereKeyNot($feeType->getKey()))
            ->exists();

        if ($codeTaken) {
            $this->fail('code', 'code_taken');
        }

        if (! in_array($data['frequency'] ?? null, FeeType::FREQUENCIES, true)) {
            $this->fail('frequency', 'invalid_frequency');
        }

        if (! in_array($appliesTo, FeeType::APPLIES_TO, true)) {
            $this->fail('applies_to', 'invalid_applies_to');
        }

        if ($priority === false || $priority < 0 || $priority > self::MAX_PRIORITY) {
            $this->fail('priority', 'priority_range');
        }

        if (
            $feeType !== null
            && $appliesTo === FeeType::APPLIES_TO_NON_RESIDENT
            && $feeType->rates()->whereNotNull('dormitory_id')->exists()
        ) {
            $this->fail('applies_to', 'has_dormitory_rates');
        }

        $feeType ??= new FeeType;

        $feeType->fill([
            'code' => $code,
            'name' => trim((string) $data['name']),
            'frequency' => $data['frequency'],
            'applies_to' => $appliesTo,
            'billing_group' => $billingGroup,
            'priority' => $priority,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        return $feeType;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.keuangan.errors.{$reason}"),
        ]);
    }
}
