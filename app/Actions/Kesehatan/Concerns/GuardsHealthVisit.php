<?php

namespace App\Actions\Kesehatan\Concerns;

use App\Models\HealthVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Pemeriksaan bersama Action kesehatan: akses, validasi isian, dan activity log `kesehatan`
 * yang tidak pernah memuat teks keluhan/tindakan/catatan. Galat memakai `pondok.health_visit.errors.*`.
 */
trait GuardsHealthVisit
{
    protected function authorize(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException(__('pondok.health_visit.errors.forbidden'));
        }
    }

    /**
     * Isian yang divalidasi; teks kosong menjadi null dan flag bawaan false.
     *
     * @param  array<string, mixed>  $data
     * @return array{visited_at: CarbonImmutable, complaint: string, treatment: ?string, note: ?string, needs_referral: bool, needs_leave: bool}
     */
    protected function validated(array $data): array
    {
        $max = HealthVisit::TEXT_MAX_LENGTH;

        $valid = Validator::make($data, [
            'visited_at' => ['required', 'date', 'before_or_equal:now'],
            'complaint' => ['required', 'string', "max:{$max}"],
            'treatment' => ['nullable', 'string', "max:{$max}"],
            'note' => ['nullable', 'string', "max:{$max}"],
            'needs_referral' => ['nullable', 'boolean'],
            'needs_leave' => ['nullable', 'boolean'],
        ], [], __('pondok.health_visit.fields'))->validate();

        return [
            'visited_at' => CarbonImmutable::parse($valid['visited_at']),
            'complaint' => $valid['complaint'],
            'treatment' => filled($valid['treatment'] ?? null) ? $valid['treatment'] : null,
            'note' => filled($valid['note'] ?? null) ? $valid['note'] : null,
            'needs_referral' => (bool) ($valid['needs_referral'] ?? false),
            'needs_leave' => (bool) ($valid['needs_leave'] ?? false),
        ];
    }

    protected function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.health_visit.errors.{$reason}"),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function log(HealthVisit $visit, User $actor, string $event, array $properties = []): void
    {
        activity()
            ->inLog('kesehatan')
            ->performedOn($visit)
            ->causedBy($actor)
            ->event($event)
            ->withProperties([
                'student_id' => $visit->student_id,
                'visited_at' => $visit->visited_at?->toDateTimeString(),
                'needs_referral' => $visit->needs_referral,
                'needs_leave' => $visit->needs_leave,
                ...$properties,
            ])
            ->log($event);
    }
}
