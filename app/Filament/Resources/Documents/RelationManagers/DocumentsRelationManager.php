<?php

namespace App\Filament\Resources\Documents\RelationManagers;

use App\Actions\Dokumen\CheckDocumentCompleteness;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Achievement;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Dokumen satu pemilik (santri, wali, staff, prestasi). Dipasang di `StudentResource`,
 * `GuardianResource`, `StaffResource`, dan `AchievementResource`. Unggah, versi baru, dan verifikasi
 * memakai aksi bersama dari `DocumentResource`.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.document.plural');
    }

    /**
     * Modul aktif, user boleh melihat dokumen dan jenis pemilik ini. Wali di luar
     * cakupan jenjang user (tidak terhubung ke santri dalam cakupan) tidak bertab.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();
        $alias = Document::aliasFor($ownerRecord);

        if (! DocumentResource::moduleEnabled() || ! $user instanceof User || $alias === null) {
            return false;
        }

        if (! Gate::allows('viewAny', Document::class) || ! Document::userSeesOwnerType($user, $alias)) {
            return false;
        }

        if ($ownerRecord instanceof Achievement) {
            return Gate::allows('view', $ownerRecord);
        }

        if ($ownerRecord instanceof Guardian && $user->scopedUnitIds() !== []) {
            return $ownerRecord->students()->withTrashed()->exists();
        }

        return true;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['documentType', 'uploader', 'documentable']))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->description(fn (): ?string => $this->completenessDescription())
            ->columns([
                DocumentResource::documentTypeColumn(),
                DocumentResource::numberColumn(),
                TextColumn::make('issued_on')
                    ->label(__('pondok.document.issued_on'))
                    ->date('j M Y')
                    ->placeholder('-'),
                TextColumn::make('expires_on')
                    ->label(__('pondok.document.expires_on'))
                    ->date('j M Y')
                    ->placeholder('-'),
                DocumentResource::statusColumn(),
                TextColumn::make('created_at')
                    ->label(__('pondok.document.uploaded_at'))
                    ->dateTime('j M Y, H.i'),
                TextColumn::make('uploader.name')
                    ->label(__('pondok.document.uploaded_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                DocumentResource::currentFilter(),
                DocumentResource::statusFilter(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading(__('pondok.document.empty'))
            ->headerActions([
                DocumentResource::uploadAction(fn (): Model => $this->getOwnerRecord()),
            ])
            ->recordActions([
                DocumentResource::actionGroup(),
            ]);
    }

    /**
     * Kelengkapan berkas wajib (santri saja).
     */
    private function completenessDescription(): ?string
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Student) {
            return null;
        }

        $items = app(CheckDocumentCompleteness::class)->handle($owner);

        if ($items === []) {
            return __('pondok.document.complete');
        }

        return __('pondok.document.incomplete', [
            'items' => collect($items)
                ->map(fn (array $item): string => $item['document_type']->name.' ('.__("pondok.document.completeness_reasons.{$item['reason']}").')')
                ->implode(', '),
        ]);
    }
}
