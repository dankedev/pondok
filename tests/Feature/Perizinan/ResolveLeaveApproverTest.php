<?php

use App\Actions\Perizinan\ResolveLeaveApprover;
use App\Models\LeaveRequest;
use App\Models\User;

beforeEach(function () {
    $this->f = perizinanFixture();
});

/**
 * @param  array<string, bool>  $expected  nama properti fixture => boleh memutus
 */
function perizinanAssertApprovers(object $f, LeaveRequest $request, array $expected, string $case): void
{
    foreach ($expected as $name => $allowed) {
        /** @var User $user */
        $user = $f->{$name};
        test()->actingAs($user);

        expect(app(ResolveLeaveApprover::class)->canDecide($user, $request))->toBe($allowed, "{$case}: {$name}");
    }
}

it('AC-5: routes KELUAR to the musyrif of the room or of the dormitory, and to nobody else on the floor', function () {
    $f = $this->f;
    $request = perizinanRaw($f->s1, $f->keluar);

    perizinanAssertApprovers($f, $request, [
        'mA' => true, 'mDorm' => true,
        'mB' => false, 'mOther' => false,
        'head' => false, 'idle' => false,
        'kes' => true, 'admin' => true,
        'pos' => false, 'bendahara' => false,
    ], 'KELUAR santri Kamar A');

    $this->actingAs($f->mA);
    expect(app(ResolveLeaveApprover::class)->pendingFor($f->mA)->pluck('id')->all())->toBe([$request->id]);

    $this->actingAs($f->mB);
    expect(app(ResolveLeaveApprover::class)->pendingFor($f->mB)->pluck('id')->all())->toBe([]);
});

it('AC-5: routes KELUAR of another dormitory to its own musyrif', function () {
    $f = $this->f;
    $request = perizinanRaw($f->s4, $f->keluar);

    perizinanAssertApprovers($f, $request, [
        'mOther' => true, 'mA' => false, 'mDorm' => false, 'head' => false, 'kes' => true,
    ], 'KELUAR santri Kamar C');
});

it('AC-5: routes PULANG to the kepala asrama and not to the musyrif', function () {
    $f = $this->f;
    $request = perizinanRaw($f->s1, $f->pulang);

    perizinanAssertApprovers($f, $request, [
        'head' => true,
        'mA' => false, 'mDorm' => false, 'mB' => false, 'mOther' => false, 'idle' => false,
        'kes' => true, 'admin' => true, 'pos' => false, 'bendahara' => false,
    ], 'PULANG santri Kamar A');
});

it('AC-5: leaves SAKIT, nonresident students and ended assignments to kesantrian and super-admin', function () {
    $f = $this->f;

    perizinanAssertApprovers($f, perizinanRaw($f->s1, $f->sakit), [
        'mA' => false, 'mDorm' => false, 'head' => false, 'idle' => false, 'kes' => true, 'admin' => true,
    ], 'SAKIT santri Kamar A');

    perizinanAssertApprovers($f, perizinanRaw($f->s3, $f->keluar), [
        'mA' => false, 'mDorm' => false, 'head' => false, 'mOther' => false, 'kes' => true, 'admin' => true,
    ], 'KELUAR santri nonmukim');

    perizinanAssertApprovers($f, perizinanRaw($f->s1, $f->keperluan), [
        'mA' => false, 'head' => false, 'kes' => true, 'admin' => true,
    ], 'KEPERLUAN santri Kamar A');
});

it('AC-5: gives kesantrian with a unit scope only the students of that unit', function () {
    $f = $this->f;

    perizinanAssertApprovers($f, perizinanRaw($f->s1, $f->keluar), ['kesSltp' => true, 'kes' => true], 'SLTP');
    perizinanAssertApprovers($f, perizinanRaw($f->s4, $f->keluar), ['kesSltp' => false, 'kes' => true], 'SLTA');

    $this->actingAs($f->kesSltp);
    $pending = app(ResolveLeaveApprover::class)->pendingFor($f->kesSltp)->pluck('student_id')->all();

    expect($pending)->toContain($f->s1->id)->not->toContain($f->s4->id);
});

it('AC-5: only requests that are still diajukan are pending for anyone', function () {
    $f = $this->f;
    $waiting = perizinanRaw($f->s1, $f->keluar);
    $decided = [];

    foreach (['disetujui', 'ditolak', 'keluar', 'kembali', 'batal'] as $status) {
        $decided[$status] = perizinanRaw($f->s1, $f->keluar, $status);
    }

    $this->actingAs($f->kes);

    expect(app(ResolveLeaveApprover::class)->pendingFor($f->kes)->pluck('id')->all())->toBe([$waiting->id]);

    foreach ($decided as $status => $request) {
        perizinanAssertApprovers($f, $request, ['kes' => false, 'admin' => false, 'mA' => false], "status {$status}");
    }
});
