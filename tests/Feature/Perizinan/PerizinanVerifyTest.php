<?php

use App\Actions\Perizinan\CancelLeaveRequest;
use App\Actions\Perizinan\CheckOutLeaveRequest;
use App\Actions\Perizinan\DecideLeaveRequest;
use App\Actions\Perizinan\GenerateHomecomingLeaves;
use App\Actions\Perizinan\ResolveLeaveApprover;
use App\Actions\Perizinan\SubmitLeaveRequest;
use App\Filament\Resources\HomecomingPeriods\HomecomingPeriodResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Schemas\LeaveRequestForm;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Models\AcademicYear;
use App\Models\DormitorySupervisor;
use App\Models\HomecomingPeriod;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = perizinanFixture();
});

function verifyList(): Testable
{
    return Livewire::test(tahfidzIndexPage(LeaveRequestResource::class));
}

/**
 * @return array{created: int, skipped: int}
 */
function verifyGenerate(object $f, HomecomingPeriod $period, ?User $by = null): array
{
    $by ??= $f->kes;
    test()->actingAs($by);

    return app(GenerateHomecomingLeaves::class)->handle($period, $by);
}

it('VERIFY: a stale approve after another approver decided never overwrites the first decision', function () {
    $f = $this->f;
    $request = perizinanRaw($f->s1, $f->keluar);
    $stale = LeaveRequest::withoutGlobalScopes()->findOrFail($request->id);

    $this->actingAs($f->kes);
    app(DecideLeaveRequest::class)->handle($request, $f->kes, false, 'Tidak diizinkan');

    $this->actingAs($f->mDorm);
    perizinanDenied(fn () => app(DecideLeaveRequest::class)->handle($stale, $f->mDorm, true));

    $fresh = perizinanFresh($request);

    expect(perizinanValue($fresh->status))->toBe('ditolak')
        ->and($fresh->approved_by)->toBe($f->kes->id)
        ->and($fresh->note)->toBe('Tidak diizinkan')
        ->and(perizinanActivities()->count())->toBe(1);
});

it('VERIFY: approving in the table after another approver decided shows a red notification and changes nothing', function () {
    $f = $this->f;
    $request = perizinanRaw($f->s1, $f->keluar);

    $this->actingAs($f->mA);
    $component = verifyList()->mountTableAction('approve', $request);

    app(DecideLeaveRequest::class)->handle($request, $f->kes, false, 'Ditolak kesantrian');

    $component->callMountedTableAction();

    $fresh = perizinanFresh($request);

    expect(perizinanValue($fresh->status))->toBe('ditolak')
        ->and($fresh->approved_by)->toBe($f->kes->id)
        ->and(json_encode(asramaNotifications()->all()))->toContain('danger');
});

it('VERIFY: a student id outside the actor scope is refused and its name never reaches the select label', function () {
    $f = $this->f;

    foreach ([[$f->kesSltp, $f->s4], [$f->mA, $f->s2], [$f->mA, $f->s4]] as [$actor, $student]) {
        $this->actingAs($actor);

        expect(LeaveRequestForm::studentLabel($student->id))->toBeNull("{$actor->id} melihat label {$student->name}");

        perizinanDenied(fn () => app(SubmitLeaveRequest::class)->handle($actor, perizinanData($f, ['student_id' => $student->id])));
    }

    expect(perizinanCount())->toBe(0);
});

it('VERIFY: the visit form does not reveal a student of another unit by id', function () {
    $f = $this->f;
    $this->actingAs($f->kesSltp);

    expect(VisitForm::studentLabel($f->s4->id))->toBeNull()
        ->and(VisitForm::guardianOptions($f->s4->id))->toBe([])
        ->and(VisitForm::studentLabel($f->s1->id))->toContain('Santri Satu');
});

it('VERIFY: "waiting for me" of a unit-scoped kesantrian never lists a request of another unit', function () {
    $f = $this->f;
    $sltp = perizinanRaw($f->s1, $f->keluar);
    $slta = perizinanRaw($f->s4, $f->keluar);

    $this->actingAs($f->kesSltp);

    verifyList()
        ->filterTable('pending_for_me', true)
        ->assertCanSeeTableRecords([$sltp])->assertCanNotSeeTableRecords([$slta]);

    expect(app(ResolveLeaveApprover::class)->canDecide($f->kesSltp, $slta))->toBeFalse();
});

it('VERIFY: the request count of a period follows the scope of the viewer', function () {
    $f = $this->f;
    $period = perizinanPeriod($f);
    verifyGenerate($f, $period);

    expect(LeaveRequest::withoutGlobalScopes()->where('homecoming_period_id', $period->id)->count())->toBe(3);

    $this->actingAs($f->kesSltp);

    Livewire::test(tahfidzIndexPage(HomecomingPeriodResource::class))
        ->assertTableColumnStateSet('leave_requests_count', 2, $period);
});

it('VERIFY: kesantrian limited to SLTP creates mass leaves only for SLTP residents', function () {
    $f = $this->f;
    $period = perizinanPeriod($f);

    $result = verifyGenerate($f, $period, $f->kesSltp);

    $studentIds = LeaveRequest::withoutGlobalScopes()->where('homecoming_period_id', $period->id)->pluck('student_id')->all();

    expect($result['created'])->toBe(2)
        ->and($studentIds)->toContain($f->s1->id, $f->s2->id)->not->toContain($f->s4->id, $f->s3->id);
});

it('VERIFY: mass leaves skip an archived resident that still has an active room assignment', function () {
    $f = $this->f;
    $period = perizinanPeriod($f);
    $archived = perizinanResident($f, $f->roomA, ['name' => 'Santri Arsip']);
    $archived->delete();

    verifyGenerate($f, $period);

    $studentIds = LeaveRequest::withoutGlobalScopes()->where('homecoming_period_id', $period->id)->pluck('student_id')->all();

    expect($studentIds)->not->toContain($archived->id)->toContain($f->s1->id);
});

it('VERIFY: a musyrif whose assignment belongs to another academic year cannot decide', function () {
    $f = $this->f;
    $old = AcademicYear::factory()->create(['name' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $user = asramaUser('musyrif');
    $staff = Staff::factory()->create(['user_id' => $user->id, 'gender' => 'L', 'position' => 'musyrif']);
    DormitorySupervisor::factory()->forRoom($f->roomA)->create(['staff_id' => $staff->id, 'academic_year_id' => $old->id, 'end_on' => null]);

    $request = perizinanRaw($f->s1, $f->keluar);
    $user = $user->fresh();

    $this->actingAs($user);

    expect(app(ResolveLeaveApprover::class)->canDecide($user, $request))->toBeFalse();

    perizinanDenied(fn () => app(DecideLeaveRequest::class)->handle($request, $user, true));

    expect(perizinanValue(perizinanFresh($request)->status))->toBe('diajukan');
});

it('VERIFY: petugas pos and bendahara cannot decide any request, even a pending one', function () {
    $f = $this->f;
    $request = perizinanRaw($f->s1, $f->pulang, 'disetujui');
    $pending = perizinanRaw($f->s2, $f->keluar);

    foreach ([$f->pos, $f->bendahara] as $actor) {
        $this->actingAs($actor);

        perizinanDenied(fn () => app(DecideLeaveRequest::class)->handle($pending, $actor, true));
        perizinanDenied(fn () => app(DecideLeaveRequest::class)->handle($request, $actor, false, 'x'));
    }

    expect(perizinanValue(perizinanFresh($pending)->status))->toBe('diajukan')
        ->and(perizinanValue(perizinanFresh($request)->status))->toBe('disetujui');
});

it('VERIFY: petugas pos limited to SLTP can neither see nor check out a leave of another unit', function () {
    $f = $this->f;
    $pos = asramaUser('petugas-pos', ['SLTP']);
    $slta = perizinanRaw($f->s4, $f->keluar, 'disetujui');
    $sltp = perizinanRaw($f->s1, $f->keluar, 'disetujui');

    $this->actingAs($pos);

    expect(LeaveRequest::query()->pluck('id')->all())->toBe([$sltp->id]);

    perizinanDenied(fn () => app(CheckOutLeaveRequest::class)->handle($slta, $pos));

    expect(perizinanValue(perizinanFresh($slta)->status))->toBe('disetujui');
});

it('VERIFY: a musyrif cannot cancel the leave of a student outside the supervised room', function () {
    $f = $this->f;
    $other = perizinanRaw($f->s2, $f->keluar);

    $this->actingAs($f->mA);

    perizinanDenied(fn () => app(CancelLeaveRequest::class)->handle($other, $f->mA));

    expect(perizinanValue(perizinanFresh($other)->status))->toBe('diajukan');
});

it('VERIFY: the list renders the leave and visit rows of an archived student for kesantrian', function () {
    $f = $this->f;
    $student = perizinanResident($f, $f->roomA, ['name' => 'Santri Arsip']);
    $request = perizinanRaw($student, $f->keluar);
    $student->delete();

    $this->actingAs($f->kes);

    verifyList()->assertCanSeeTableRecords([$request])->assertSee('Santri Arsip');
});

it('VERIFY: seeding the demo does not leave the frozen test clock behind', function () {
    $this->travelTo(Carbon::parse('2026-10-16 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');
    $before = Carbon::getTestNow();

    $this->seed(DemoSeeder::class);

    expect(Carbon::getTestNow()?->toIso8601String())->toBe($before->toIso8601String());
})->group('seeder-demo');
