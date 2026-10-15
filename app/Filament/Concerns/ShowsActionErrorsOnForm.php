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
        try {
            return $save();
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
