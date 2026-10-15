<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\FeeOverride;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed([UnitSeeder::class, RolePermissionSeeder::class]);
    Filament::setCurrentPanel('admin');
});

it('creates a user with roles and a unit scope', function () {
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));
    $sltp = Unit::query()->where('code', 'SLTP')->sole();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Admin SLTP',
            'email' => 'sltp@pondok.test',
            'password' => 'kata-sandi-1',
            'is_active' => true,
            'roles' => [Role::findByName('admin-sekolah')->id],
            'units' => [$sltp->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'sltp@pondok.test')->sole();

    expect($user->hasRole('admin-sekolah'))->toBeTrue()
        ->and($user->scopedUnitIds())->toBe([$sltp->id]);
});

it('keeps the password when it is left empty on edit', function () {
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));
    $user = User::factory()->create(['password' => 'kata-sandi-lama']);
    $hash = $user->password;

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['name' => 'Nama Baru', 'password' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh())
        ->name->toBe('Nama Baru')
        ->password->toBe($hash);
});

it('does not let a non-super-admin grant the super-admin role', function () {
    $manager = User::factory()->create();
    $manager->givePermissionTo(['view-dashboard', 'view-any:user', 'create:user']);
    $this->actingAs($manager);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Penyusup',
            'email' => 'penyusup@pondok.test',
            'password' => 'kata-sandi-1',
            'roles' => [Role::findByName('super-admin')->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['roles.0']);

    expect(User::query()->where('email', 'penyusup@pondok.test')->exists())->toBeFalse();
});

it('AC-35: hides delete for users who approved overrides', function () {
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));
    $approver = User::factory()->create();
    $plain = User::factory()->create();
    FeeOverride::factory()->create(['approved_by' => $approver->id]);

    Livewire::test(EditUser::class, ['record' => $approver->id])->assertActionHidden('delete');
    Livewire::test(EditUser::class, ['record' => $plain->id])->assertActionVisible('delete');
});
