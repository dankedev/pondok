<?php

namespace Database\Seeders;

use App\Actions\Core\ActivateAcademicYear;
use App\Models\AcademicYear;
use App\Models\Applicant;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Post;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\Demo\DemoAcademics;
use Database\Seeders\Demo\DemoAdmissions;
use Database\Seeders\Demo\DemoContent;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoData;
use Database\Seeders\Demo\DemoFinance;
use Database\Seeders\Demo\DemoRecords;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Data demo realistis Ma'had Al-Khair Belitang (F034). Dijalankan terpisah:
 * `php artisan db:seed --class=DemoSeeder`. Tidak pernah dipanggil seeder
 * instalasi. Menolak production, idempotent (jalan ulang tidak menambah
 * data), dan membuat data lewat Action agar aturan bisnis berlaku.
 *
 * Seeder melepas status login di proses pemanggil (`Auth::forgetGuards()`)
 * agar cakupan jenjang user login tidak memengaruhi data.
 */
class DemoSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'demo-alkhair-belitang';

    public const EMAIL_DOMAIN = 'demo.test';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(__('pondok.demo.refused_production'));
        }

        Auth::forgetGuards();

        $this->call(InstallSeeder::class);

        $context = new DemoContext($this->command);

        try {
            $this->seedAcademicYears($context);
            $this->renameUnits();
            $context->units = Unit::query()->get()->keyBy('code')->all();
            $this->seedAccounts($context);
            $this->seedStaff($context);

            (new DemoContent($context))->profile();
            (new DemoAcademics($context))->run();
            (new DemoFinance($context))->run();
            (new DemoAdmissions($context))->run();
            // Konten lebih dulu: berita prestasi memakai kategori "Prestasi Al-Khair".
            (new DemoContent($context))->run();
            (new DemoRecords($context))->run();
        } finally {
            $context->files->cleanup();
        }

        $this->command?->info(__('pondok.demo.summary', [
            'students' => Student::withoutUnitScope()->count(),
            'invoices' => Invoice::withoutUnitScope()->count(),
            'payments' => Payment::withoutUnitScope()->count(),
            'applicants' => Applicant::withoutUnitScope()->count(),
            'posts' => Post::query()->count(),
        ]));
    }

    private function seedAcademicYears(DemoContext $context): void
    {
        $years = [];

        foreach ([-1, 0, 1] as $offset) {
            $data = $context->calendar->academicYear($offset);
            $years[$offset] = AcademicYear::query()->firstOrCreate(['name' => $data['name']], $data);
        }

        if (! $years[0]->is_active) {
            app(ActivateAcademicYear::class)->handle($years[0]);
        }

        $context->previousYear = $years[-1];
        $context->activeYear = $years[0]->refresh();
        $context->nextYear = $years[1];
    }

    /**
     * Nama jenjang demo (MTW/SMP, TSN/SMA), hanya bila nama masih bawaan instalasi.
     */
    private function renameUnits(): void
    {
        $defaults = array_column(UnitSeeder::UNITS, 'name', 'code');

        foreach (DemoData::UNIT_NAMES as $code => $name) {
            Unit::query()->where('code', $code)->where('name', $defaults[$code])->update(['name' => $name]);
        }
    }

    private function seedAccounts(DemoContext $context): void
    {
        $password = config('pondok.demo.password');

        if (blank($password)) {
            $password = self::DEFAULT_PASSWORD;
            $this->command?->warn(__('pondok.demo.default_password_warning'));
        }

        foreach (DemoData::ACCOUNTS as $role => $account) {
            $email = $role.'@'.self::EMAIL_DOMAIN;
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $account['name'],
                    'email' => $email,
                    'password' => (string) $password,
                    'is_active' => true,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->assignRole($role);
                $user->units()->sync(array_map(fn (string $code): int => $context->unit($code)->id, $account['units']));
            }

            $context->users[$role] = $user;
        }
    }

    private function seedStaff(DemoContext $context): void
    {
        foreach (DemoData::STAFF as $row) {
            $staff = Staff::query()->firstOrCreate(['nip' => $row['nip']], [
                'name' => $row['name'],
                'gender' => $row['gender'],
                'phone' => '0812000090'.substr($row['nip'], -2),
                'position' => $row['position'],
                'is_active' => true,
            ]);

            if (isset($row['account']) && $staff->user_id === null) {
                $userId = $context->user($row['account'])->id;

                if (! Staff::query()->where('user_id', $userId)->exists()) {
                    $staff->forceFill(['user_id' => $userId])->save();
                }
            }

            $context->staff[$row['nip']] = $staff;
        }
    }
}
