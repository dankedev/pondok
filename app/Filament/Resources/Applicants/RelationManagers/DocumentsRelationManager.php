<?php

namespace App\Filament\Resources\Applicants\RelationManagers;

use App\Actions\Psb\VerifyApplicantDocument;
use App\Filament\Resources\Applicants\ApplicantResource;
use App\Models\ApplicantDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Berkas unggahan satu pendaftar. Tanpa Create, Edit, maupun Delete: berkas
 * diunggah pendaftar dari halaman status dan diverifikasi lewat
 * `VerifyApplicantDocument`.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.applicant_document.plural');
    }

    /**
     * Aksi verifikasi kustom tetap tampil di halaman detail pendaftar.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('requirement'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('requirement.name')
                    ->label(__('pondok.applicant_document.requirement')),
                TextColumn::make('original_name')
                    ->label(__('pondok.applicant_document.original_name')),
                TextColumn::make('size_kb')
                    ->label(__('pondok.applicant_document.size_kb'))
                    ->formatStateUsing(fn (int $state): string => __('pondok.applicant_document.size_value', ['size' => number_format($state, 0, ',', '.')]))
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('updated_at')
                    ->label(__('pondok.applicant_document.uploaded_at'))
                    ->dateTime('j M Y, H.i'),
                TextColumn::make('status')
                    ->label(__('pondok.applicant_document.status'))
                    ->badge()
                    ->formatStateUsing(fn (ApplicantDocument $record): string => $record->statusLabel())
                    ->color(fn (ApplicantDocument $record): string => $record->statusColor()),
                TextColumn::make('note')
                    ->label(__('pondok.applicant_document.note'))
                    ->placeholder('-')
                    ->wrap(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedDocument)
            ->emptyStateHeading(__('pondok.applicant_document.empty'))
            ->recordActions([
                Action::make('download')
                    ->label(__('pondok.applicant_document.download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->url(fn (ApplicantDocument $record): string => url("/admin/applicant-documents/{$record->getKey()}"), shouldOpenInNewTab: true),
                Action::make('markValid')
                    ->label(__('pondok.applicant_document.mark_valid'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->authorize(fn (): bool => $this->canVerify())
                    ->hidden(fn (ApplicantDocument $record): bool => $record->status === ApplicantDocument::STATUS_VALID)
                    ->action(fn (ApplicantDocument $record, VerifyApplicantDocument $verify, Action $action) => $this->verify($verify, $action, $record, ApplicantDocument::STATUS_VALID)),
                Action::make('reject')
                    ->label(__('pondok.applicant_document.reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize(fn (): bool => $this->canVerify())
                    ->schema([
                        Textarea::make('note')
                            ->label(__('pondok.applicant_document.reject_note'))
                            ->helperText(__('pondok.applicant_document.reject_note_helper'))
                            ->required()
                            ->maxLength(255)
                            ->rows(2),
                    ])
                    ->action(fn (ApplicantDocument $record, array $data, VerifyApplicantDocument $verify, Action $action) => $this->verify($verify, $action, $record, ApplicantDocument::STATUS_REJECTED, $data['note'] ?? null)),
            ]);
    }

    private function canVerify(): bool
    {
        return ApplicantResource::can('verifyDocument', $this->getOwnerRecord());
    }

    private function verify(VerifyApplicantDocument $verify, Action $action, ApplicantDocument $document, string $status, ?string $note = null): void
    {
        ApplicantResource::verifyShowingFormErrors($action, fn () => $verify->handle($document, auth()->user(), $status, $note));

        Notification::make()->title(__('pondok.applicant_document.verified'))->success()->send();
    }
}
