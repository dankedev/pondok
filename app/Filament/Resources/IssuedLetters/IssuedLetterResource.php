<?php

namespace App\Filament\Resources\IssuedLetters;

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Actions\Dokumen\IssueLetter;
use App\Actions\Dokumen\LetterNumbering;
use App\Actions\Dokumen\UpdateLetterNumbering;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\IssuedLetters\Pages\ListIssuedLetters;
use App\Models\IssuedLetter;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Surat keluar (F014): daftar, terbitkan lewat modal, detail, cetak,
 * batalkan, dan atur penomoran. Tanpa halaman create/edit dan tanpa hapus:
 * koreksi surat lewat pembatalan lalu terbit ulang.
 */
class IssuedLetterResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = IssuedLetter::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 3;

    /** Field modal "Terbitkan surat"; galat Action `student` tampil di field santri. */
    private const ISSUE_FIELD_MAP = [
        'student' => 'student_id',
        'type' => 'type',
        'signed_by_staff_id' => 'signed_by_staff_id',
        'purpose' => 'purpose',
    ];

    public static function getModule(): string
    {
        return 'dokumen';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.issued_letter.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.issued_letter.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.dokumen');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student.unit', 'issuer']))
            ->columns([
                TextColumn::make('letter_no')
                    ->label(__('pondok.issued_letter.letter_no'))
                    ->searchable()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('student.name')
                    ->label(__('pondok.issued_letter.student'))
                    ->description(fn (IssuedLetter $record): ?string => $record->student?->nis)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('student', fn (Builder $query) => $query
                        ->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('nis', 'like', "%{$search}%")))),
                TextColumn::make('type')
                    ->label(__('pondok.issued_letter.type'))
                    ->formatStateUsing(fn (string $state): string => IssuedLetter::typeLabelFor($state)),
                TextColumn::make('issued_on')
                    ->label(__('pondok.issued_letter.issued_on'))
                    ->date('j M Y'),
                TextColumn::make('signer_name')
                    ->label(__('pondok.issued_letter.signer'))
                    ->state(fn (IssuedLetter $record): ?string => $record->snapshot['signer']['name'] ?? null)
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label(__('pondok.issued_letter.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("pondok.issued_letter.statuses.{$state}"))
                    ->color(fn (string $state): string => IssuedLetter::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('student.unit.name')
                    ->label(__('pondok.common.unit'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('purpose')
                    ->label(__('pondok.issued_letter.purpose'))
                    ->placeholder('-')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issuer.name')
                    ->label(__('pondok.issued_letter.issued_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('pondok.issued_letter.type'))
                    ->options(collect(IssuedLetter::TYPES)->mapWithKeys(fn (string $type): array => [$type => IssuedLetter::typeLabelFor($type)])->all()),
                SelectFilter::make('status')
                    ->label(__('pondok.issued_letter.status'))
                    ->options(collect(IssuedLetter::STATUSES)->mapWithKeys(fn (string $status): array => [$status => __("pondok.issued_letter.statuses.{$status}")])->all()),
                SelectFilter::make('unit')
                    ->label(__('pondok.common.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query
                        ->whereIn('student_id', Student::withoutGlobalScopes()->where('unit_id', $data['value'])->select('id'))),
                SelectFilter::make('year')
                    ->label(__('pondok.issued_letter.year'))
                    ->options(fn (): array => IssuedLetter::query()->distinct()->orderByDesc('sequence_year')->pluck('sequence_year', 'sequence_year')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->where('sequence_year', (int) $data['value'])),
            ])
            ->emptyStateIcon(Heroicon::OutlinedEnvelope)
            ->emptyStateHeading(__('pondok.issued_letter.empty'))
            ->emptyStateDescription(__('pondok.issued_letter.empty_description'))
            ->recordActions([
                ActionGroup::make([
                    static::viewAction(),
                    static::printAction(),
                    static::cancelAction(),
                ]),
            ]);
    }

    public static function printUrl(IssuedLetter $letter): string
    {
        return route('filament.admin.dokumen.letters.print', ['issuedLetter' => $letter]);
    }

    public static function issueAction(): Action
    {
        return Action::make('issue')
            ->label(__('pondok.issued_letter.issue'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => static::can('create'))
            ->modalHeading(__('pondok.issued_letter.issue'))
            ->modalDescription(__('pondok.issued_letter.issue_hint_date'))
            ->modalSubmitActionLabel(__('pondok.issued_letter.issue_submit'))
            ->schema([
                Select::make('student_id')
                    ->label(__('pondok.issued_letter.student'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Student::query()
                        ->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('nis', 'like', "%{$search}%"))
                        ->orderBy('name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Student $student): array => [$student->id => static::studentLabel($student)])
                        ->all())
                    // Id di luar cakupan tetap diteruskan ke `IssueLetter` (pesan `student_not_found`) tanpa membuka namanya.
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value)
                        ? (($student = Student::query()->find($value)) ? static::studentLabel($student) : __('pondok.issued_letter.student_unavailable'))
                        : null)
                    ->required(),
                Select::make('type')
                    ->label(__('pondok.issued_letter.type'))
                    ->options(collect(IssuedLetter::TYPES)->mapWithKeys(fn (string $type): array => [$type => IssuedLetter::typeLabelFor($type)])->all())
                    ->required()
                    ->live(),
                Select::make('signed_by_staff_id')
                    ->label(__('pondok.issued_letter.signer'))
                    ->options(fn (): array => Staff::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Staff $staff): array => [$staff->id => filled($staff->position)
                            ? __('pondok.issued_letter.signer_option', ['name' => $staff->name, 'position' => $staff->position])
                            : $staff->name])
                        ->all())
                    ->searchable()
                    ->required(),
                Textarea::make('purpose')
                    ->label(__('pondok.issued_letter.purpose'))
                    ->helperText(__('pondok.issued_letter.purpose_helper'))
                    ->rows(2)
                    ->maxLength(IssuedLetter::PURPOSE_MAX_LENGTH)
                    ->hidden(fn (Get $get): bool => $get('type') === IssuedLetter::TYPE_STUDENT_CARD)
                    ->required(fn (Get $get): bool => $get('type') !== IssuedLetter::TYPE_STUDENT_CARD),
            ])
            ->action(function (array $data, IssueLetter $issueLetter, Action $action): void {
                $student = is_numeric($data['student_id'] ?? null) ? Student::withoutGlobalScopes()->find((int) $data['student_id']) : null;

                $letter = static::runIssueAction($action, function () use ($student, $data, $issueLetter): IssuedLetter {
                    if ($student === null) {
                        throw ValidationException::withMessages(['student' => __('pondok.dokumen.errors.student_not_found')]);
                    }

                    return $issueLetter->handle($student, [
                        'type' => $data['type'] ?? null,
                        'signed_by_staff_id' => $data['signed_by_staff_id'] ?? null,
                        'purpose' => $data['purpose'] ?? null,
                    ], static::user());
                });

                Notification::make()
                    ->title(__('pondok.issued_letter.issued', ['number' => $letter->letter_no]))
                    ->success()
                    ->actions([
                        Action::make('print')
                            ->label(__('pondok.issued_letter.print'))
                            ->button()
                            ->url(static::printUrl($letter), shouldOpenInNewTab: true),
                    ])
                    ->send();
            });
    }

    public static function viewAction(): Action
    {
        $entry = fn (string $key, string $label): TextEntry => TextEntry::make("detail_{$key}")
            ->label($label)
            ->state(fn (Get $get): mixed => $get("detail.{$key}"))
            ->placeholder('-');

        return Action::make('view')
            ->label(__('pondok.issued_letter.view'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->authorize(fn (IssuedLetter $record): bool => Gate::allows('view', $record))
            ->mountUsing(function (Schema $schema, IssuedLetter $record, HasActions $livewire): void {
                $schema->fill(['detail' => static::detail($record)]);
                $livewire->forceRender();
            })
            ->modalHeading(fn (IssuedLetter $record): string => $record->letter_no)
            ->modalSubmitAction(false)
            ->schema([
                Hidden::make('detail')->dehydrated(false),
                Grid::make(2)->schema([
                    $entry('letter_no', __('pondok.issued_letter.letter_no')),
                    $entry('type', __('pondok.issued_letter.type')),
                    $entry('student', __('pondok.issued_letter.student')),
                    $entry('issued_on', __('pondok.issued_letter.issued_on')),
                    $entry('purpose', __('pondok.issued_letter.purpose')),
                    $entry('signer', __('pondok.issued_letter.signer')),
                    $entry('verification_code', __('pondok.issued_letter.verification_code')),
                    $entry('status', __('pondok.issued_letter.status'))
                        ->badge()
                        ->color(fn (Get $get): string => IssuedLetter::STATUS_COLORS[$get('detail.status_key')] ?? 'gray'),
                    $entry('verification_url', __('pondok.issued_letter.verification_url'))->columnSpanFull(),
                    $entry('issued_by', __('pondok.issued_letter.issued_by')),
                    $entry('cancelled_at', __('pondok.issued_letter.cancelled_at'))
                        ->visible(fn (Get $get): bool => filled($get('detail.cancelled_at'))),
                    $entry('cancelled_by', __('pondok.issued_letter.cancelled_by'))
                        ->visible(fn (Get $get): bool => filled($get('detail.cancelled_at'))),
                    $entry('cancel_reason', __('pondok.issued_letter.cancel_reason'))
                        ->visible(fn (Get $get): bool => filled($get('detail.cancelled_at'))),
                ]),
            ]);
    }

    public static function printAction(): Action
    {
        return Action::make('print')
            ->label(__('pondok.issued_letter.print'))
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->authorize(fn (IssuedLetter $record): bool => Gate::allows('view', $record))
            ->url(fn (IssuedLetter $record): string => static::printUrl($record), shouldOpenInNewTab: true);
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('pondok.issued_letter.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (IssuedLetter $record): string => __('pondok.issued_letter.cancel').' '.$record->letter_no)
            ->modalDescription(__('pondok.issued_letter.cancel_description'))
            ->modalSubmitActionLabel(__('pondok.issued_letter.cancel_submit'))
            ->authorize(fn (IssuedLetter $record): bool => Gate::allows('cancel', $record))
            ->hidden(fn (IssuedLetter $record): bool => ! $record->isCancellable())
            ->schema([
                TextInput::make('reason')
                    ->label(__('pondok.issued_letter.cancel_reason_label'))
                    ->required()
                    ->maxLength(IssuedLetter::REASON_MAX_LENGTH),
            ])
            ->action(function (IssuedLetter $record, array $data, CancelIssuedLetter $cancel, Action $action): void {
                try {
                    $cancel->handle($record, static::user(), (string) ($data['reason'] ?? ''));
                } catch (ValidationException $exception) {
                    // Sudah dibatalkan di tempat lain: bukan kesalahan isian form.
                    if (array_key_exists('letter', $exception->errors())) {
                        Notification::make()->title($exception->errors()['letter'][0])->danger()->send();
                        $action->cancel();

                        return;
                    }

                    static::runShowingFormErrors($action, fn () => throw $exception);
                }

                Notification::make()
                    ->title(__('pondok.issued_letter.cancelled', ['number' => $record->letter_no]))
                    ->success()
                    ->send();
            });
    }

    public static function configureNumberingAction(): Action
    {
        return Action::make('configureNumbering')
            ->label(__('pondok.letter_numbering.title'))
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->color('gray')
            ->authorize(fn (): bool => Gate::allows('configureNumbering', IssuedLetter::class))
            ->modalHeading(__('pondok.letter_numbering.title'))
            ->modalSubmitActionLabel(__('pondok.letter_numbering.submit'))
            // Form diisi nilai yang berlaku, lalu halaman dirender penuh agar pratinjau nomor ikut tampil.
            ->mountUsing(function (Schema $schema, LetterNumbering $numbering, HasActions $livewire): void {
                $settings = $numbering->settings();

                $schema->fill([
                    'number_format' => $settings['format'],
                    'school_code' => static::storedSchoolCode(),
                    'type_codes' => $settings['type_codes'],
                    'sequence_padding' => $settings['padding'],
                ]);
                $livewire->forceRender();
            })
            ->schema(fn (LetterNumbering $numbering): array => [
                TextInput::make('number_format')
                    ->label(__('pondok.letter_numbering.format'))
                    ->helperText(__('pondok.letter_numbering.format_help'))
                    ->required()
                    ->maxLength(100)
                    ->live(debounce: 500)
                    ->afterStateUpdated(static::refreshPreview()),
                Grid::make(2)->schema([
                    TextInput::make('school_code')
                        ->label(__('pondok.letter_numbering.school_code'))
                        ->helperText(__('pondok.letter_numbering.school_code_help'))
                        ->placeholder(fn (): string => $numbering->defaultSchoolCode((string) Setting::get('school.name', config('app.name'))))
                        ->maxLength(10)
                        ->live(debounce: 500)
                        ->afterStateUpdated(static::refreshPreview()),
                    TextInput::make('sequence_padding')
                        ->label(__('pondok.letter_numbering.sequence_padding'))
                        ->helperText(__('pondok.letter_numbering.sequence_padding_help'))
                        ->numeric()
                        ->required()
                        ->live(debounce: 500)
                        ->afterStateUpdated(static::refreshPreview()),
                ]),
                Fieldset::make(__('pondok.letter_numbering.type_codes'))
                    ->statePath('type_codes')
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema(collect(IssuedLetter::TYPES)->map(fn (string $type): TextInput => TextInput::make($type)
                        ->label(IssuedLetter::typeLabelFor($type))
                        ->required()
                        ->maxLength(10)
                        ->live(debounce: 500)
                        ->afterStateUpdated(static::refreshPreview()))->all()),
                TextEntry::make('preview')
                    ->label(__('pondok.letter_numbering.preview'))
                    ->state(fn (Get $get): string => static::numberingPreview($numbering, $get))
                    ->extraAttributes(['class' => 'tabular-nums']),
            ])
            ->action(function (array $data, UpdateLetterNumbering $update, Action $action): void {
                static::runShowingFormErrors($action, fn () => $update->handle([
                    'number_format' => $data['number_format'] ?? null,
                    'school_code' => $data['school_code'] ?? null,
                    'type_codes' => $data['type_codes'] ?? [],
                    'sequence_padding' => $data['sequence_padding'] ?? null,
                ], static::user()));

                Notification::make()
                    ->title(__('pondok.letter_numbering.saved'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Render ulang halaman agar pratinjau nomor mengikuti isian (pola modal detail).
     */
    protected static function refreshPreview(): Closure
    {
        return fn (HasActions $livewire) => $livewire->forceRender();
    }

    /**
     * Contoh nomor `keterangan_aktif` urutan 45 hari ini dari isian modal penomoran.
     */
    protected static function numberingPreview(LetterNumbering $numbering, Get $get): string
    {
        try {
            $current = $numbering->settings();
            $schoolCode = strtoupper(trim((string) $get('school_code')));
            $typeCodes = array_map(fn (mixed $code): string => strtoupper(trim((string) $code)), (array) ($get('type_codes') ?? []));

            return $numbering->format(IssuedLetter::TYPE_ACTIVE_STATEMENT, CarbonImmutable::now('Asia/Jakarta'), 45, [
                'format' => trim((string) $get('number_format')) ?: $current['format'],
                'school_code' => $schoolCode !== '' ? $schoolCode : $numbering->defaultSchoolCode((string) Setting::get('school.name', config('app.name'))),
                'type_codes' => [...$current['type_codes'], ...array_filter($typeCodes)],
                'padding' => max(1, min(LetterNumbering::MAX_PADDING, (int) ($get('sequence_padding') ?: $current['padding']))),
            ]);
        } catch (Throwable) {
            return __('pondok.letter_numbering.preview_invalid');
        }
    }

    /**
     * Isi modal detail surat.
     *
     * @return array<string, ?string>
     */
    protected static function detail(IssuedLetter $record): array
    {
        $record->loadMissing(['student', 'issuer', 'canceller']);
        $signer = $record->snapshot['signer'] ?? [];

        return [
            'letter_no' => $record->letter_no,
            'type' => $record->typeLabel(),
            'student' => $record->student !== null ? static::studentLabel($record->student) : ($record->snapshot['student']['name'] ?? null),
            'issued_on' => $record->issued_on?->locale('id')->translatedFormat('j M Y'),
            'purpose' => $record->purpose,
            'signer' => collect([$signer['name'] ?? null, $signer['position'] ?? null])->filter()->implode(', '),
            'verification_code' => $record->formattedVerificationCode(),
            'verification_url' => $record->verificationUrl(),
            'status' => $record->statusLabel(),
            'status_key' => $record->status,
            'issued_by' => $record->issuer?->name,
            'cancelled_at' => $record->cancelled_at?->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j M Y, H.i'),
            'cancelled_by' => $record->canceller?->name,
            'cancel_reason' => $record->cancel_reason,
        ];
    }

    protected static function studentLabel(Student $student): string
    {
        return __('pondok.issued_letter.student_option', ['nis' => $student->nis, 'name' => $student->name]);
    }

    protected static function storedSchoolCode(): ?string
    {
        $code = Setting::get('letters.school_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Jalankan `IssueLetter`: galat field tampil di form modal, bentrok nomor sebagai notifikasi.
     *
     * @param  Closure(): IssuedLetter  $run
     */
    protected static function runIssueAction(Action $action, Closure $run): IssuedLetter
    {
        try {
            return $run();
        } catch (ValidationException $exception) {
            $errors = collect($exception->errors());
            $other = $errors->except(array_keys(self::ISSUE_FIELD_MAP));

            if ($other->isNotEmpty()) {
                Notification::make()->title($other->flatten()->first())->danger()->send();
                $action->halt();
            }

            return static::runShowingFormErrors($action, fn () => throw ValidationException::withMessages(
                $errors->mapWithKeys(fn (array $messages, string $field): array => [self::ISSUE_FIELD_MAP[$field] => $messages])->all(),
            ));
        }
    }

    protected static function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIssuedLetters::route('/'),
        ];
    }
}
