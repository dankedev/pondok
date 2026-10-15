<?php

use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

function userWithRole(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

it('lets admin-sekolah manage every akademik resource', function () {
    $user = userWithRole('admin-sekolah');
    $student = Student::factory()->create();

    foreach (['/admin/students', '/admin/students/create', "/admin/students/{$student->id}/edit", '/admin/cohorts', '/admin/classrooms', '/admin/guardians', '/admin/guardians/create'] as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }
});

it('lets kesantrian view students and guardians but not change them', function () {
    $user = userWithRole('kesantrian');
    $student = Student::factory()->create();

    $this->actingAs($user)->get('/admin/students')->assertOk();
    $this->actingAs($user)->get('/admin/guardians')->assertOk();
    $this->actingAs($user)->get('/admin/students/create')->assertForbidden();
    $this->actingAs($user)->get("/admin/students/{$student->id}/edit")->assertForbidden();
    $this->actingAs($user)->get('/admin/cohorts')->assertForbidden();
    $this->actingAs($user)->get('/admin/classrooms')->assertForbidden();
});

it('forbids akademik resources to roles without akademik permissions', function (string $role) {
    $user = userWithRole($role);

    foreach (['/admin/students', '/admin/cohorts', '/admin/classrooms', '/admin/guardians'] as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
})->with(['bendahara', 'admin-konten', 'admin-psb', 'musyrif', 'ustadz-tahfidz', 'petugas-pos']);

it('returns 403 and hides the menu when the akademik module is disabled', function () {
    Setting::set('modules.akademik.enabled', false);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->get('/admin/students')->assertForbidden();
    $this->actingAs($user)->get('/admin/classrooms')->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee(__('pondok.navigation.akademik'));
});

it('shows the akademik menu when the module is enabled', function () {
    $this->actingAs(userWithRole('super-admin'))->get('/admin')->assertSee(__('pondok.navigation.akademik'));
});
