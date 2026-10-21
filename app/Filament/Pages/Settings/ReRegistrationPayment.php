<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Psb\UpdateReRegistrationMinimums;
use App\Enums\Module;
use App\Models\Unit;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Nominal minimal pembayaran daftar ulang per jenjang (F040). Akses lewat
 * `view:re-registration-payment` (admin-psb dan super-admin); hanya jenjang
 * dalam cakupan user yang tampil.
 */
class ReRegistrationPayment extends NumberingSettingsPage
{
    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'settings/re-registration-payment';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.psb');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.psb.re_registration_settings.title');
    }

    public function getTitle(): string
    {
        return __('pondok.psb.re_registration_settings.title');
    }

    protected static function pagePermission(): string
    {
        return 'view:re-registration-payment';
    }

    public static function getModule(): string
    {
        return Module::Psb->value;
    }

    protected function savedMessage(): string
    {
        return __('pondok.psb.re_registration_settings.saved');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.psb.re_registration_settings.heading'))
                    ->description(__('pondok.psb.re_registration_settings.description'))
                    ->schema($this->units()
                        ->map(fn (Unit $unit): TextInput => TextInput::make("amounts.{$unit->id}")
                            ->label($unit->name)
                            ->helperText(__('pondok.psb.re_registration_settings.helper'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(UpdateReRegistrationMinimums::MAX_AMOUNT)
                            ->prefix('Rp')
                            ->nullable()
                            ->extraInputAttributes(['class' => 'tabular-nums']))
                        ->all()),
            ]);
    }

    /**
     * @return array{amounts: array<int, int|null>}
     */
    protected function fillData(): array
    {
        return ['amounts' => $this->units()->pluck('min_reregistration_amount', 'id')->all()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function persist(array $data, User $user): void
    {
        $amounts = collect($data['amounts'] ?? [])
            ->map(fn (mixed $amount): mixed => filled($amount) ? $amount : null)
            ->all();

        app(UpdateReRegistrationMinimums::class)->handle($amounts, $user);
    }

    /**
     * Jenjang dalam cakupan user login (kosong = semua), urut `sort_order`.
     *
     * @return Collection<int, Unit>
     */
    private function units(): Collection
    {
        return Unit::query()->accessible()->orderBy('sort_order')->get();
    }
}
