<?php

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\HealthVisit;
use App\Models\LeaveRequest;
use App\Models\Student;
use App\Models\User;
use App\Models\Visit;
use App\Policies\HomecomingPeriodPolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\LeaveTypePolicy;
use App\Policies\VisitPolicy;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = perizinanFixture();
});

/** Urutan: izin, jenis izin, periode libur, kunjungan, form ajukan izin, form catat kunjungan. */
const PERIZINAN_URLS = ['/admin/leave-requests', '/admin/leave-types', '/admin/homecoming-periods', '/admin/visits', '/admin/leave-requests/create', '/admin/visits/create'];

it('AC-12: serves the perizinan pages to each role as the plan says (:role)', function (string $role, array $statuses) {
    $this->actingAs(asramaUser($role));

    foreach (PERIZINAN_URLS as $index => $url) {
        expect($this->get($url)->status())->toBe($statuses[$index], "{$role} {$url}");
    }
})->with([
    'super-admin' => ['super-admin', [200, 200, 200, 200, 200, 200]],
    'kesantrian' => ['kesantrian', [200, 200, 200, 200, 200, 200]],
    'musyrif' => ['musyrif', [200, 403, 403, 403, 200, 403]],
    'petugas-pos' => ['petugas-pos', [200, 403, 403, 200, 403, 200]],
    'bendahara' => ['bendahara', [403, 403, 403, 403, 403, 403]],
    'admin-konten' => ['admin-konten', [403, 403, 403, 403, 403, 403]],
    'admin-sekolah' => ['admin-sekolah', [403, 403, 403, 403, 403, 403]],
    'admin-psb' => ['admin-psb', [403, 403, 403, 403, 403, 403]],
    'ustadz-tahfidz' => ['ustadz-tahfidz', [403, 403, 403, 403, 403, 403]],
]);

it('AC-8: grants no role the right to delete leave requests, visits, leave types or periods', function () {
    $f = $this->f;
    $records = [
        [new LeaveRequestPolicy, perizinanRaw($f->s1, $f->keluar)],
        [new VisitPolicy, perizinanRawVisit($f->s1)],
        [new LeaveTypePolicy, $f->keluar],
        [new HomecomingPeriodPolicy, perizinanPeriod($f)],
    ];

    foreach ([$f->admin, $f->kes, $f->mA, $f->pos, $f->bendahara] as $user) {
        foreach ($records as [$policy, $record]) {
            expect(method_exists($policy, 'delete') ? $policy->delete($user, $record) : false)->toBeFalse(class_basename($policy));

            if (method_exists($policy, 'deleteAny')) {
                expect($policy->deleteAny($user))->toBeFalse(class_basename($policy));
            }
        }
    }

    $this->actingAs($f->admin);
    $table = Livewire::test(tahfidzIndexPage(LeaveRequestResource::class))->instance()->getTable();

    expect($table->getFlatBulkActions())->toBeEmpty();
});

it('AC-12: shows petugas pos only approved and outside leave requests', function () {
    $f = $this->f;
    $rows = [];

    foreach (['diajukan', 'disetujui', 'ditolak', 'keluar', 'kembali', 'batal'] as $status) {
        $rows[$status] = perizinanRaw(perizinanResident($f), $f->keluar, $status);
    }

    $this->actingAs($f->pos);

    expect(LeaveRequest::query()->pluck('id')->sort()->values()->all())->toBe(collect([$rows['disetujui']->id, $rows['keluar']->id])->sort()->values()->all());

    Livewire::test(tahfidzIndexPage(LeaveRequestResource::class))
        ->assertCanSeeTableRecords([$rows['disetujui'], $rows['keluar']])
        ->assertCanNotSeeTableRecords([$rows['diajukan'], $rows['ditolak'], $rows['kembali'], $rows['batal']]);

    $this->actingAs($f->kes);

    expect(LeaveRequest::query()->count())->toBe(6);
});

it('AC-13: shows a musyrif only the leave requests of active residents of the supervised room or dormitory', function () {
    $f = $this->f;
    $a = perizinanRaw($f->s1, $f->keluar);
    $b = perizinanRaw($f->s2, $f->keluar);
    $nonResident = perizinanRaw($f->s3, $f->keluar);
    $otherUnit = perizinanRaw($f->s4, $f->keluar);

    $this->actingAs($f->mA);

    expect(LeaveRequest::query()->pluck('id')->all())->toBe([$a->id]);
    Livewire::test(tahfidzIndexPage(LeaveRequestResource::class))
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b, $nonResident, $otherUnit]);

    $this->actingAs($f->mDorm);
    expect(LeaveRequest::query()->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());

    $this->actingAs($f->idle);
    expect(LeaveRequest::query()->count())->toBe(0);
});

it('AC-13: scopes leave requests and visits to the unit of kesantrian', function () {
    $f = $this->f;
    $sltp = perizinanRaw($f->s1, $f->keluar);
    $slta = perizinanRaw($f->s4, $f->keluar);
    $visitSltp = perizinanRawVisit($f->s1);
    $visitSlta = perizinanRawVisit($f->s4);

    $this->actingAs($f->kesSltp);

    expect(LeaveRequest::query()->pluck('id')->all())->toBe([$sltp->id])
        ->and(Visit::query()->pluck('id')->all())->toBe([$visitSltp->id]);

    $this->get("/admin/visits/{$visitSltp->id}/edit")->assertOk();
    $this->get("/admin/visits/{$visitSlta->id}/edit")->assertNotFound();

    $this->actingAs($f->kes);

    expect(LeaveRequest::query()->count())->toBe(2)->and(Visit::query()->count())->toBe(2);
});

it('AC-13: fails closed for a user without any leave permission', function () {
    $f = $this->f;
    perizinanRaw($f->s1, $f->keluar);

    foreach ([$f->bendahara, User::factory()->create(), asramaUser('ustadz-tahfidz')] as $user) {
        $this->actingAs($user);

        expect(LeaveRequest::query()->count())->toBe(0);
    }

    $this->actingAs($f->kes);
    expect(LeaveRequest::query()->count())->toBe(1);
});

it('AC-13: keeps the leave requests and visits of an archived student visible to kesantrian', function () {
    $f = $this->f;
    $leave = perizinanRaw($f->s1, $f->keluar);
    $visit = perizinanRawVisit($f->s1);

    $f->s1->delete();

    foreach ([$f->kes, $f->kesSltp] as $user) {
        $this->actingAs($user);

        expect(LeaveRequest::query()->pluck('id')->all())->toBe([$leave->id])
            ->and(Visit::query()->pluck('id')->all())->toBe([$visit->id]);
    }

    expect(Student::onlyTrashed()->count())->toBe(1);
});

it('AC-13: shares the student visibility with the health module through Student::visibleStudentsFor', function () {
    $f = $this->f;

    foreach (['mA', 'mDorm', 'idle', 'kes', 'kesSltp', 'pos', 'bendahara'] as $name) {
        $ids = fn ($query): array => $query->pluck('id')->sort()->values()->all();

        expect($ids(Student::visibleStudentsFor($f->{$name})))->toBe($ids(HealthVisit::visibleStudents($f->{$name})), $name);
    }

    expect(Student::visibleStudentsFor($f->mA)->pluck('id')->all())->toBe([$f->s1->id])
        ->and(Student::visibleStudentsFor($f->kesSltp)->pluck('id')->all())->not->toContain($f->s4->id);
});
