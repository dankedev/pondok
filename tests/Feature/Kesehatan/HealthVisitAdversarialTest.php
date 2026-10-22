<?php

use App\Actions\Kesehatan\DeleteHealthVisit;
use App\Actions\Kesehatan\UpdateHealthVisit;
use App\Filament\Resources\HealthVisits\HealthVisitResource;
use App\Filament\Resources\HealthVisits\Pages\CreateHealthVisit;
use App\Filament\Resources\HealthVisits\Pages\EditHealthVisit;
use App\Filament\Resources\HealthVisits\Pages\ListHealthVisits;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\RelationManagers\HealthVisitsRelationManager;
use App\Models\HealthVisit;
use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kesehatanFixture();
});

it('VERIFY: super-admin with a unit_user row only sees the visits of that unit', function () {
    $f = $this->f;
    kesehatanRaw($f->s1);
    $slta = kesehatanRaw($f->s4);
    $scoped = asramaUser('super-admin', ['SLTP']);

    $this->actingAs($scoped);

    expect(HealthVisit::query()->count())->toBe(1)
        ->and(HealthVisit::query()->whereKey($slta->id)->exists())->toBeFalse();
    $this->get("/admin/health-visits/{$slta->id}/edit")->assertNotFound();
});

it('VERIFY: a forged student_id of another unit in the create form creates nothing and leaks no name', function () {
    $f = $this->f;
    $this->actingAs($f->kesSltp);

    Livewire::test(CreateHealthVisit::class)
        ->set('data.student_id', $f->s4->id)
        ->set('data.visited_at', '2026-10-14 16:30')
        ->set('data.complaint', 'Demam')
        ->call('create')
        ->assertDontSee($f->s4->name)
        ->assertDontSee($f->s4->nis);

    expect(HealthVisit::withoutGlobalScopes()->count())->toBe(0);
});

it('VERIFY: a forged non-resident student_id in the create form is refused for a musyrif', function () {
    $f = $this->f;
    $this->actingAs($f->musyrif);

    Livewire::test(CreateHealthVisit::class)
        ->set('data.student_id', $f->s3->id)
        ->set('data.visited_at', '2026-10-14 16:30')
        ->set('data.complaint', 'Demam')
        ->call('create');

    expect(HealthVisit::withoutGlobalScopes()->count())->toBe(0);
});

it('VERIFY: a forged student_id or recorded_by in the edit payload changes nothing', function () {
    $f = $this->f;
    $visit = kesehatanRaw($f->s1, ['recorded_by' => $f->kes->id]);
    $this->actingAs($f->kes);

    Livewire::test(EditHealthVisit::class, ['record' => $visit->getKey()])
        ->set('data.student_id', $f->s2->id)
        ->set('data.recorded_by', $f->admin->id)
        ->set('data.complaint', 'Batuk berdahak')
        ->call('save');

    $fresh = $visit->fresh();

    expect($fresh->student_id)->toBe($f->s1->id)
        ->and($fresh->recorded_by)->toBe($f->kes->id)
        ->and($fresh->complaint)->toBe('Batuk berdahak');
});

it('VERIFY: kesantrian and musyrif cannot delete through the table, the edit header or the Action', function () {
    $f = $this->f;
    $visit = kesehatanRaw($f->s1);

    $this->actingAs($f->kes);
    Livewire::test(ListHealthVisits::class)->assertTableActionHidden('delete', $visit);
    Livewire::test(EditHealthVisit::class, ['record' => $visit->getKey()])->assertActionHidden('delete');
    expect(fn () => app(DeleteHealthVisit::class)->handle($visit, $f->kes))->toThrow(AuthorizationException::class);

    $this->actingAs($f->musyrif);
    expect(fn () => app(DeleteHealthVisit::class)->handle($visit, $f->musyrif))->toThrow(AuthorizationException::class);

    expect(HealthVisit::withoutGlobalScopes()->whereKey($visit->id)->exists())->toBeTrue();
});

it('VERIFY: a musyrif cannot update a visit through the Action or the edit page', function () {
    $f = $this->f;
    $visit = kesehatanRaw($f->s1);
    $this->actingAs($f->musyrif);

    expect(fn () => app(UpdateHealthVisit::class)->handle($visit, $f->musyrif, ['visited_at' => '2026-10-14 16:30', 'complaint' => 'Diubah']))
        ->toThrow(AuthorizationException::class);
    expect($visit->fresh()->complaint)->toBe('Batuk pilek');

    Livewire::test(EditHealthVisit::class, ['record' => $visit->getKey()])->assertForbidden();
});

it('VERIFY: a musyrif loses sight of a former resident after the room assignment ended', function () {
    $f = $this->f;
    $visit = kesehatanRaw($f->s1);
    $f->s1->roomAssignments()->update(['end_on' => '2026-10-01']);

    $this->actingAs($f->musyrif);

    expect(HealthVisit::query()->whereKey($visit->id)->exists())->toBeFalse()
        ->and(HealthVisit::visibleStudents($f->musyrif)->count())->toBe(0);
});

it('VERIFY: the student tab never lists out-of-scope visits to a musyrif or a unit-scoped user', function () {
    $f = $this->f;
    kesehatanRaw($f->s2);
    $slta = kesehatanRaw($f->s4);

    $this->actingAs($f->musyrif);
    Livewire::test(HealthVisitsRelationManager::class, ['ownerRecord' => $f->s2, 'pageClass' => EditStudent::class])
        ->assertCountTableRecords(0);

    $this->actingAs($f->kesSltp);
    Livewire::test(HealthVisitsRelationManager::class, ['ownerRecord' => $f->s4, 'pageClass' => EditStudent::class])
        ->assertCanNotSeeTableRecords([$slta]);
});

it('VERIFY: visits of a soft-deleted student stay listed and editable for kesantrian', function () {
    $f = $this->f;
    $visit = kesehatanRaw($f->s1);
    $f->s1->delete();

    $this->actingAs($f->kes);
    Livewire::test(ListHealthVisits::class)->assertCanSeeTableRecords([$visit]);
    $this->get("/admin/health-visits/{$visit->id}/edit")->assertOk();

    $this->actingAs($f->kesSltp);
    expect(HealthVisit::query()->whereKey($visit->id)->exists())->toBeTrue();
});

it('VERIFY: the resource is not globally searchable, so encrypted text cannot leak through search', function () {
    $this->actingAs($this->f->admin);

    expect(HealthVisitResource::canGloballySearch())->toBeFalse();
});

it('VERIFY: the edit URL is closed when the module is off, even for super-admin', function () {
    $f = $this->f;
    $visit = kesehatanRaw($f->s1);
    Setting::set('modules.kesehatan.enabled', false);

    $this->actingAs($f->admin);

    $this->get("/admin/health-visits/{$visit->id}/edit")->assertForbidden();
    expect(HealthVisit::withoutGlobalScopes()->count())->toBe(1);
});

it('VERIFY: the activity log never holds the health text after create, update and delete', function () {
    $f = $this->f;
    $visit = kesehatanRecord($f, $f->kes, ['complaint' => 'RAHASIA-KELUHAN', 'treatment' => 'RAHASIA-OBAT', 'note' => 'RAHASIA-CATATAN']);
    app(UpdateHealthVisit::class)->handle($visit, $f->kes, kesehatanData($f, ['complaint' => 'RAHASIA-BARU', 'treatment' => 'RAHASIA-OBAT', 'note' => 'RAHASIA-CATATAN']));
    $this->actingAs($f->admin);
    app(DeleteHealthVisit::class)->handle($visit->fresh(), $f->admin);

    $dump = kesehatanActivities()->map(fn ($a) => json_encode([$a->properties, $a->description]))->implode('|');

    expect(kesehatanActivities())->toHaveCount(3)
        ->and($dump)->not->toContain('RAHASIA');
});
