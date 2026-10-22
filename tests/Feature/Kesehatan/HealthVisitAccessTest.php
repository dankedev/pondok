<?php

use App\Actions\Kesehatan\RecordHealthVisit;
use App\Filament\Resources\HealthVisits\HealthVisitResource;
use App\Filament\Resources\HealthVisits\Pages\CreateHealthVisit;
use App\Filament\Resources\HealthVisits\Pages\ListHealthVisits;
use App\Models\HealthVisit;
use App\Policies\HealthVisitPolicy;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kesehatanFixture();
});

it('AC-8: opens the health visit pages for super-admin, kesantrian and musyrif (:role)', function (string $role) {
    $user = asramaUser($role);
    $this->actingAs($user);

    expect(HealthVisitResource::canViewAny())->toBeTrue();
    $this->get('/admin/health-visits')->assertOk();
    $this->get('/admin/health-visits/create')->assertOk();
})->with(['super-admin', 'kesantrian', 'musyrif']);

it('AC-8: closes the health visit pages for every other role (:role)', function (string $role) {
    $user = asramaUser($role);
    $this->actingAs($user);

    expect(HealthVisitResource::canViewAny())->toBeFalse();
    $this->get('/admin/health-visits')->assertForbidden();
    $this->get('/admin/health-visits/create')->assertForbidden();
})->with(['admin-sekolah', 'bendahara', 'admin-konten', 'admin-psb', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-8: lets kesantrian edit and denies musyrif the edit page and the edit action', function () {
    $f = $this->f;
    $visit = kesehatanRaw($f->s1);

    $this->actingAs($f->kes)->get("/admin/health-visits/{$visit->id}/edit")->assertOk();
    $this->actingAs($f->musyrif)->get("/admin/health-visits/{$visit->id}/edit")->assertForbidden();

    expect($f->kes->can('update', $visit))->toBeTrue()
        ->and($f->musyrif->can('update', $visit))->toBeFalse()
        ->and($f->musyrif->can('view', $visit))->toBeTrue();

    $this->actingAs($f->musyrif);
    Livewire::test(ListHealthVisits::class)
        ->assertCanSeeTableRecords([$visit])
        ->assertTableActionHidden('edit', $visit)
        ->assertTableActionVisible('view', $visit);
});

it('AC-8: lets a musyrif record a visit for a student of their own dormitory', function () {
    $f = $this->f;

    $visit = kesehatanRecord($f, $f->musyrif);

    expect($visit->recorded_by)->toBe($f->musyrif->id)->and(HealthVisit::query()->count())->toBe(1);
});

it('AC-7: allows delete for super-admin only, with no bulk delete for anyone', function () {
    $f = $this->f;
    $visit = kesehatanRaw($f->s1);

    expect($f->admin->can('delete', $visit))->toBeTrue()
        ->and($f->kes->can('delete', $visit))->toBeFalse()
        ->and($f->musyrif->can('delete', $visit))->toBeFalse()
        ->and((new HealthVisitPolicy)->deleteAny($f->admin))->toBeFalse();

    foreach ([$f->admin, $f->kes] as $user) {
        $this->actingAs($user);
        $table = Livewire::test(ListHealthVisits::class)->instance()->getTable();

        expect($table->getFlatBulkActions())->toBeEmpty();
    }

    $this->actingAs($f->kes);
    Livewire::test(ListHealthVisits::class)->assertTableActionHidden('delete', $visit);

    $this->actingAs($f->admin);
    Livewire::test(ListHealthVisits::class)->assertTableActionVisible('delete', $visit);
});

it('AC-9: scopes visits to the unit of kesantrian and lets an unscoped user see all', function () {
    $f = $this->f;
    $sltp = kesehatanRaw($f->s1);
    $slta = kesehatanRaw($f->s4);

    $this->actingAs($f->kesSltp);
    Livewire::test(ListHealthVisits::class)->assertCanSeeTableRecords([$sltp])->assertCanNotSeeTableRecords([$slta]);

    $this->get("/admin/health-visits/{$sltp->id}/edit")->assertOk();
    $this->get("/admin/health-visits/{$slta->id}/edit")->assertNotFound();
    expect(HealthVisit::query()->pluck('id')->all())->toBe([$sltp->id]);

    $this->actingAs($f->kes);
    Livewire::test(ListHealthVisits::class)->assertCanSeeTableRecords([$sltp, $slta]);
});

it('AC-9: refuses to record for a student of another unit and offers none in the form', function () {
    $f = $this->f;
    $this->actingAs($f->kesSltp);

    tahfidzRejected(fn () => app(RecordHealthVisit::class)->handle($f->kesSltp, kesehatanData($f, ['student_id' => $f->s4->id])), 'student_id');

    $options = kesehatanStudentOptions(Livewire::test(CreateHealthVisit::class), 'Santri');

    expect($options)->toContain($f->s1->id)->not->toContain($f->s4->id);
});

it('AC-10: shows a musyrif only visits of active residents of the supervised dormitory', function () {
    $f = $this->f;
    $own = kesehatanRaw($f->s1);
    $otherRoom = kesehatanRaw($f->s2);
    $nonResident = kesehatanRaw($f->s3);
    $otherUnit = kesehatanRaw($f->s4);

    $this->actingAs($f->musyrif);

    Livewire::test(ListHealthVisits::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$otherRoom, $nonResident, $otherUnit]);

    expect(HealthVisit::query()->pluck('id')->all())->toBe([$own->id]);
});

it('AC-10: offers a musyrif only the supervised residents in the form and rejects others in the Action', function () {
    $f = $this->f;
    $this->actingAs($f->musyrif);

    $options = kesehatanStudentOptions(Livewire::test(CreateHealthVisit::class), 'Santri');

    expect($options)->toBe([$f->s1->id]);
    expect(HealthVisit::visibleStudents($f->musyrif)->pluck('id')->all())->toBe([$f->s1->id])
        ->and(HealthVisit::visibleStudents($f->kes)->pluck('id')->all())->toContain($f->s1->id, $f->s2->id, $f->s3->id, $f->s4->id);

    tahfidzRejected(fn () => app(RecordHealthVisit::class)->handle($f->musyrif, kesehatanData($f, ['student_id' => $f->s2->id])), 'student_id');
});

it('AC-10: shows an empty list to a musyrif without an active supervision', function () {
    $f = $this->f;
    kesehatanRaw($f->s1);

    $this->actingAs($f->idle);

    expect(HealthVisit::query()->count())->toBe(0)
        ->and(HealthVisit::visibleStudents($f->idle)->count())->toBe(0);
    Livewire::test(ListHealthVisits::class)->assertCountTableRecords(0);
});
