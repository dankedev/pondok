<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Actions\Akademik\AttachGuardian;
use App\Actions\Akademik\SetBillingContact;
use App\Filament\Resources\Guardians\GuardianResource;
use App\Filament\Resources\Guardians\Schemas\GuardianForm;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Guardian;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Wali santri. Penghubungan memakai Action `AttachGuardian` agar selalu ada
 * tepat satu kontak tagihan.
 */
class GuardiansRelationManager extends RelationManager
{
    protected static string $relationship = 'guardians';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.guardian.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.common.name'))
                    ->url(fn (Guardian $record): ?string => GuardianResource::canEdit($record) ? GuardianResource::getUrl('edit', ['record' => $record]) : null),
                TextColumn::make('relation')
                    ->label(__('pondok.guardian.relation'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.guardian.relations.{$state}")),
                TextColumn::make('phone')
                    ->label(__('pondok.common.phone')),
                IconColumn::make('is_billing_contact')
                    ->label(__('pondok.guardian.is_billing_contact'))
                    ->boolean(),
            ])
            ->headerActions([
                $this->attachExistingAction(),
                $this->createAndAttachAction(),
            ])
            ->recordActions([
                Action::make('setBillingContact')
                    ->label(__('pondok.guardian.set_billing_contact'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->authorize(fn (): bool => StudentResource::canEdit($this->getOwnerRecord()))
                    ->hidden(fn (Guardian $record): bool => (bool) $record->pivot->is_billing_contact)
                    ->action(function (Guardian $record, SetBillingContact $setBillingContact): void {
                        $setBillingContact->handle($this->studentRecord(), $record);

                        Notification::make()
                            ->title(__('pondok.guardian.billing_contact_set', ['name' => $record->name]))
                            ->success()
                            ->send();
                    }),
                DetachAction::make()
                    ->authorize(fn (): bool => StudentResource::canEdit($this->getOwnerRecord())),
            ]);
    }

    private function attachExistingAction(): Action
    {
        return Action::make('attachGuardian')
            ->label(__('pondok.guardian.attach'))
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->authorize(fn (): bool => StudentResource::canEdit($this->getOwnerRecord()))
            ->schema([
                Select::make('guardian_id')
                    ->label(__('pondok.guardian.label'))
                    ->getSearchResultsUsing(fn (string $search): array => Guardian::query()
                        ->whereKeyNot($this->studentRecord()->guardians()->pluck('guardians.id'))
                        ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Guardian $guardian): array => [$guardian->id => "{$guardian->name} ({$guardian->phone})"])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Guardian::query()->find($value)?->name)
                    ->searchable()
                    ->required(),
                ...$this->pivotFields(),
            ])
            ->action(function (array $data, AttachGuardian $attach): void {
                $attach->handle(
                    $this->studentRecord(),
                    Guardian::query()->findOrFail($data['guardian_id']),
                    $data['relation'],
                    (bool) $data['is_billing_contact'],
                );
            });
    }

    private function createAndAttachAction(): Action
    {
        return Action::make('createGuardian')
            ->label(__('pondok.guardian.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => StudentResource::canEdit($this->getOwnerRecord()) && GuardianResource::canCreate())
            ->schema([
                Section::make()->columns(2)->schema(GuardianForm::fields()),
                ...$this->pivotFields(),
            ])
            ->action(function (array $data, AttachGuardian $attach): void {
                $guardian = Guardian::query()->create(collect($data)->only(['name', 'phone', 'occupation', 'address'])->all());

                $attach->handle($this->studentRecord(), $guardian, $data['relation'], (bool) $data['is_billing_contact']);
            });
    }

    /**
     * @return list<Select|Toggle>
     */
    private function pivotFields(): array
    {
        return [
            Select::make('relation')
                ->label(__('pondok.guardian.relation'))
                ->options(__('pondok.guardian.relations'))
                ->required(),
            Toggle::make('is_billing_contact')
                ->label(__('pondok.guardian.is_billing_contact'))
                ->helperText(__('pondok.guardian.is_billing_contact_helper'))
                ->default(false),
        ];
    }

    private function studentRecord(): Student
    {
        /** @var Student */
        return $this->getOwnerRecord();
    }
}
