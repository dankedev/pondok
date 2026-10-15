<?php

namespace App\Filament\Concerns;

use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Jalankan Action class dari aksi modal Filament. Kegagalan validasi Action
 * dipetakan ke field form modal sehingga error tampil di bawah field terkait
 * dan modal tetap terbuka.
 */
trait ShowsActionErrorsOnForm
{
    /**
     * @template TModel of Model
     *
     * @param  Closure(): TModel  $save
     * @return TModel
     */
    protected static function saveShowingFormErrors(Action $action, Closure $save): Model
    {
        return static::runShowingFormErrors($action, $save);
    }

    /**
     * Sama dengan `saveShowingFormErrors`, untuk Action yang tidak mengembalikan model.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $run
     * @return TResult
     */
    protected static function runShowingFormErrors(Action $action, Closure $run): mixed
    {
        try {
            return $run();
        } catch (ValidationException $exception) {
            $statePath = "mountedActions.{$action->getNestingIndex()}.data";

            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $field): array => ["{$statePath}.{$field}" => $messages])
                    ->all(),
            );
        }
    }
}
