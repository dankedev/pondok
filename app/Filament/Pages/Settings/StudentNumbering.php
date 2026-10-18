<?php

namespace App\Filament\Pages\Settings;

use App\Actions\Akademik\GenerateStudentNumber;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengaturan format NIS (F039). Akses lewat `view:student-numbering`
 * (admin-sekolah dan super-admin).
 */
class StudentNumbering extends NumberingSettingsPage
{
    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'settings/student-numbering';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.pengaturan');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.numbering.student.title');
    }

    public function getTitle(): string
    {
        return __('pondok.numbering.student.title');
    }

    protected static function pagePermission(): string
    {
        return 'view:student-numbering';
    }

    protected static function moduleKey(): ?string
    {
        return 'akademik';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('pondok.numbering.student.heading'))
                    ->description(__('pondok.numbering.student.description'))
                    ->schema([
                        TextInput::make('nis_format')
                            ->label(__('pondok.numbering.student.format_label'))
                            ->helperText(__('pondok.numbering.student.format_helper'))
                            ->required()
                            ->maxLength(30)
                            ->live(),
                        Placeholder::make('preview')
                            ->label(__('pondok.numbering.preview'))
                            ->content(fn (Get $get): string => $this->previewFor($get('nis_format'))),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected function fillData(): array
    {
        return [
            'nis_format' => app(GenerateStudentNumber::class)->resolvedFormat(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function persist(array $data, User $user): void
    {
        $generator = app(GenerateStudentNumber::class);

        try {
            $format = $generator->validateFormat($data['nis_format'] ?? null);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'nis_format' => $exception->errors()['format'][0] ?? __('pondok.numbering.invalid_format'),
            ]);
        }

        DB::transaction(function () use ($format, $user): void {
            $old = Setting::query()->find(GenerateStudentNumber::SETTING_KEY)?->value;

            Setting::set(GenerateStudentNumber::SETTING_KEY, $format);

            activity('akademik')
                ->causedBy($user)
                ->event('numbering_updated')
                ->withProperties(['old' => $old, 'new' => $format])
                ->log('numbering_updated');
        });
    }

    public function previewFor(?string $format): string
    {
        $unit = Unit::query()->orderBy('sort_order')->first();

        if ($unit === null) {
            return __('pondok.numbering.no_unit');
        }

        try {
            return app(GenerateStudentNumber::class)->preview(
                (string) $format,
                $unit,
                (int) CarbonImmutable::now(config('app.timezone'))->format('Y'),
            );
        } catch (ValidationException) {
            return __('pondok.numbering.invalid_format');
        }
    }
}
