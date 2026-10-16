<?php

namespace Database\Seeders\Demo;

use App\Models\AcademicYear;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Keadaan bersama antarbagian seeder demo: kalender, berkas sementara,
 * tahun ajaran, jenjang, akun aktor, dan staff.
 */
final class DemoContext
{
    public readonly DemoCalendar $calendar;

    public readonly DemoFiles $files;

    public AcademicYear $previousYear;

    public AcademicYear $activeYear;

    public AcademicYear $nextYear;

    /** @var array<string, Unit> kode jenjang => jenjang */
    public array $units = [];

    /** @var array<string, User> peran => akun demo */
    public array $users = [];

    /** @var array<string, Staff> NIP => staff */
    public array $staff = [];

    public function __construct(public readonly ?Command $command = null)
    {
        $this->calendar = new DemoCalendar;
        $this->files = new DemoFiles;
    }

    public function unit(string $code): Unit
    {
        return $this->units[$code];
    }

    public function user(string $role): User
    {
        return $this->users[$role];
    }

    public function staffByPosition(string $position, ?string $gender = null): array
    {
        return array_values(array_filter(
            $this->staff,
            fn (Staff $staff): bool => $staff->position === $position && ($gender === null || $staff->gender === $gender),
        ));
    }

    public function mudir(): Staff
    {
        return $this->staffByPosition('Mudir')[0];
    }

    /**
     * Santri demo (non-PSB) menurut nama; termasuk yang terhapus, tanpa cakupan jenjang.
     */
    public function student(string $name): Student
    {
        return Student::withoutUnitScope()->withTrashed()->whereNull('applicant_id')->where('name', $name)->firstOrFail();
    }

    public function skip(string $part, string $reason): void
    {
        $this->command?->warn(__('pondok.demo.skipped', ['part' => $part, 'reason' => $reason]));
    }
}
