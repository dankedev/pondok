<?php

namespace App\Actions\Asrama;

use App\Models\Dormitory;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Student;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Support\Carbon;

/**
 * Menempatkan satu santri dari baris template penghuni. Penempatan sebenarnya
 * lewat AssignRoom (kapasitas, jenis kelamin, pindah kamar, idempoten).
 */
class ImportRoomAssignmentRow
{
    public function __construct(private AssignRoom $assignRoom) {}

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws RowImportFailedException
     */
    public function handle(array $row): RoomAssignment
    {
        // UnitScope berlaku: santri di luar cakupan jenjang sama dengan tidak ada.
        $student = Student::query()->where('nis', trim((string) $row['nis']))->first()
            ?? throw new RowImportFailedException(__('pondok.asrama.import.errors.student_unknown'));

        $room = $this->findRoom((string) $row['dormitory'], (string) $row['room']);

        $startOn = filled($row['start_on'] ?? null) ? Carbon::parse($row['start_on']) : null;

        return $this->assignRoom->handle($student, $room, $startOn);
    }

    private function findRoom(string $dormitoryName, string $roomName): Room
    {
        $dormitories = Dormitory::query()->get()
            ->filter(fn (Dormitory $dormitory): bool => $this->sameName($dormitory->name, $dormitoryName));

        if ($dormitories->count() !== 1) {
            throw new RowImportFailedException(__('pondok.asrama.import.errors.dormitory_unknown'));
        }

        $rooms = Room::query()
            ->whereBelongsTo($dormitories->first())
            ->get()
            ->filter(fn (Room $room): bool => $this->sameName($room->name, $roomName));

        if ($rooms->count() !== 1) {
            throw new RowImportFailedException(__('pondok.asrama.import.errors.room_unknown'));
        }

        return $rooms->first();
    }

    /**
     * Dibandingkan di PHP: `lower()` SQLite tidak mengecilkan huruf non-ASCII.
     */
    private function sameName(string $stored, string $given): bool
    {
        return mb_strtolower(trim($stored)) === mb_strtolower(trim($given));
    }
}
