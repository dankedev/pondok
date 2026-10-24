<?php

namespace App\Filament\Resources\AttendanceSessions;

use App\Enums\Module;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\AttendanceSessions\Pages\CreateAttendanceSession;
use App\Filament\Resources\AttendanceSessions\Pages\EditAttendanceSession;
use App\Filament\Resources\AttendanceSessions\Pages\ListAttendanceSessions;
use App\Filament\Resources\AttendanceSessions\Schemas\AttendanceSessionForm;
use App\Filament\Resources\AttendanceSessions\Tables\AttendanceSessionsTable;
use App\Models\AttendanceSession;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Absensi harian per rombel (F025). Catat dan ubah lewat `App\Actions\Absensi\RecordClassroomAttendance`;
 * cakupan rombel dari global scope `visible` di `AttendanceSession`. Tanpa hapus (D8).
 */
class AttendanceSessionResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = AttendanceSession::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 5;

    public static function getModule(): string
    {
        return Module::Absensi->value;
    }

    public static function getModelLabel(): string
    {
        return __('pondok.attendance.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.attendance.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.akademik');
    }

    public static function form(Schema $schema): Schema
    {
        return AttendanceSessionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendanceSessionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceSessions::route('/'),
            'create' => CreateAttendanceSession::route('/create'),
            'edit' => EditAttendanceSession::route('/{record}/edit'),
        ];
    }

    /**
     * Notifikasi simpan yang menyebut rombel dan tanggal sesi.
     */
    public static function savedMessage(AttendanceSession $record): string
    {
        return __('pondok.attendance.saved', [
            'classroom' => $record->classroom?->name,
            'date' => $record->held_on->translatedFormat('j M Y'),
        ]);
    }

    public static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
