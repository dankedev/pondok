<?php

namespace App\Actions\Asrama;

use App\Models\Dormitory;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah kamar asrama.
 *
 * Kapasitas tidak boleh di bawah jumlah penghuni aktif (semua jenjang), dan
 * kamar berpenghuni tidak boleh dinonaktifkan.
 */
class SaveRoom
{
    /**
     * @param  array{name: string, floor?: int|null, capacity: int, is_active?: bool}  $data
     */
    public function handle(Dormitory $dormitory, array $data, ?Room $room = null): Room
    {
        $capacity = (int) $data['capacity'];
        $isActive = (bool) ($data['is_active'] ?? true);

        if ($capacity < 1) {
            $this->fail('capacity', 'capacity_min');
        }

        return DB::transaction(function () use ($dormitory, $data, $room, $capacity, $isActive): Room {
            if ($room !== null) {
                // Kunci baris kamar yang sama dengan AssignRoom agar hitungan penghuni tidak usang.
                $room = Room::query()->lockForUpdate()->findOrFail($room->id);
                $occupied = $room->occupiedCount();

                if ($capacity < $occupied) {
                    $this->fail('capacity', 'capacity_below_occupancy');
                }

                if (! $isActive && $room->is_active && $occupied > 0) {
                    $this->fail('is_active', 'room_has_residents');
                }
            }

            $room ??= new Room(['dormitory_id' => $dormitory->id]);

            $room->fill([
                'name' => $data['name'],
                'floor' => filled($data['floor'] ?? null) ? (int) $data['floor'] : null,
                'capacity' => $capacity,
                'is_active' => $isActive,
            ])->save();

            return $room;
        });
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.asrama.errors.{$reason}"),
        ]);
    }
}
