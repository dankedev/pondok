<?php

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Untuk halaman form penuh (Create/Edit/halaman kustom dengan state `data`):
 * kegagalan validasi Action dipetakan ke `data.<field>` sehingga error tampil di
 * bawah field terkait.
 */
trait ShowsActionErrorsOnPageForm
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $run
     * @return TResult
     */
    protected function runShowingPageFormErrors(Closure $run): mixed
    {
        try {
            return $run();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                    ->all(),
            );
        }
    }
}
