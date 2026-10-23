<?php

namespace App\Actions\Perizinan\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Pemeriksaan bersama Action perizinan: akses, galat validasi `pondok.<bagian>.errors.*`,
 * dan activity log `perizinan` (F024 D9).
 */
trait GuardsPerizinan
{
    protected function authorize(bool $allowed, string $section = 'leave_request'): void
    {
        if (! $allowed) {
            throw new AuthorizationException(__("pondok.{$section}.errors.forbidden"));
        }
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    protected function fail(string $field, string $reason, array $replace = [], string $section = 'leave_request'): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.{$section}.errors.{$reason}", $replace),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function log(Model $subject, User $actor, string $event, array $properties = []): void
    {
        activity()
            ->inLog('perizinan')
            ->performedOn($subject)
            ->causedBy($actor)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
