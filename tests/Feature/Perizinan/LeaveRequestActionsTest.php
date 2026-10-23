<?php

use App\Actions\Perizinan\CancelLeaveRequest;
use App\Actions\Perizinan\CheckInLeaveRequest;
use App\Actions\Perizinan\CheckOutLeaveRequest;
use App\Actions\Perizinan\DecideLeaveRequest;
use App\Actions\Perizinan\SubmitLeaveRequest;
use App\Models\LeaveRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = perizinanFixture();
});

it('AC-3: lets kesantrian and musyrif submit a request that starts as diajukan, with an activity', function () {
    $f = $this->f;

    $viaKes = perizinanSubmit($f, $f->kes, ['requested_via' => 'wali']);
    $viaMusyrif = perizinanSubmit($f, $f->mDorm, ['student_id' => $f->s2->id]);

    $rows = [[$viaKes, $f->kes, 'wali', $f->s1], [$viaMusyrif, $f->mDorm, 'musyrif', $f->s2]];

    foreach ($rows as [$request, $actor, $via, $student]) {
        $row = perizinanFresh($request);

        expect(perizinanValue($row->status))->toBe('diajukan')
            ->and($row->requested_by)->toBe($actor->id)
            ->and(perizinanValue($row->requested_via))->toBe($via)
            ->and($row->student_id)->toBe($student->id)
            ->and($row->reason)->toBe('Berobat ke dokter gigi')
            ->and($row->planned_departure->format('Y-m-d H:i'))->toBe('2026-10-15 13:00')
            ->and($row->return_due->format('Y-m-d H:i'))->toBe('2026-10-15 17:00');
    }

    $created = perizinanActivities('created');

    expect($created)->toHaveCount(2)
        ->and($created->pluck('subject_id')->all())->toBe([$viaKes->id, $viaMusyrif->id])
        ->and($created->pluck('causer_id')->all())->toBe([$f->kes->id, $f->mDorm->id]);
});

it('AC-3: refuses petugas pos and bendahara without creating a row', function () {
    $f = $this->f;

    foreach ([$f->pos, $f->bendahara] as $actor) {
        $this->actingAs($actor);

        expect(fn () => app(SubmitLeaveRequest::class)->handle($actor, perizinanData($f)))->toThrow(AuthorizationException::class);
    }

    expect(perizinanCount())->toBe(0)->and(perizinanActivities())->toBeEmpty();
});

it('AC-4: rejects invalid requests without creating a row', function () {
    $f = $this->f;
    $lulus = tahfidzStudent($f, 'L', null, ['status' => 'lulus']);
    asramaResident($lulus, $f->roomA);

    $busy = [];

    foreach (['diajukan', 'disetujui', 'keluar'] as $status) {
        $busy[$status] = perizinanResident($f);
        perizinanRaw($busy[$status], $f->keluar, $status);
    }

    $cases = [
        'alasan kosong' => [$f->kes, ['reason' => ''], 'reason'],
        'alasan tidak dikirim' => [$f->kes, ['reason' => null], 'reason'],
        'alasan 1001 karakter' => [$f->kes, ['reason' => str_repeat('a', 1001)], 'reason'],
        'batas kembali sama dengan keluar' => [$f->kes, ['return_due' => '2026-10-15 13:00'], ['return_due', 'planned_departure']],
        'batas kembali sebelum keluar' => [$f->kes, ['return_due' => '2026-10-15 12:00'], ['return_due', 'planned_departure']],
        'durasi 7 jam untuk KELUAR (maks 6)' => [$f->kes, ['return_due' => '2026-10-15 20:00'], ['return_due', 'planned_departure']],
        'PULANG tanpa nama penjemput' => [$f->kes, ['leave_type_id' => $f->pulang->id, 'return_due' => '2026-10-20 17:00'], 'pickup_name'],
        'jenis izin nonaktif' => [$f->kes, ['leave_type_id' => $f->sakit->id], 'leave_type_id'],
        'santri lulus' => [$f->kes, ['student_id' => $lulus->id], 'student_id'],
        'santri tidak ada' => [$f->kes, ['student_id' => 999999], 'student_id'],
        'santri di luar jenjang kesantrian' => [$f->kesSltp, ['student_id' => $f->s4->id], 'student_id'],
        'santri di luar binaan musyrif (kamar lain)' => [$f->mA, ['student_id' => $f->s2->id], 'student_id'],
        'santri nonmukim untuk musyrif' => [$f->mA, ['student_id' => $f->s3->id], 'student_id'],
        'musyrif tanpa penugasan aktif' => [$f->idle, [], 'student_id'],
        'santri masih punya izin diajukan' => [$f->kes, ['student_id' => $busy['diajukan']->id], 'student_id'],
        'santri masih punya izin disetujui' => [$f->kes, ['student_id' => $busy['disetujui']->id], 'student_id'],
        'santri masih sedang keluar' => [$f->kes, ['student_id' => $busy['keluar']->id], 'student_id'],
    ];

    $f->sakit->forceFill(['is_active' => false])->save();

    foreach ($cases as $label => [$actor, $overrides, $fields]) {
        $this->actingAs($actor);

        try {
            tahfidzRejected(fn () => app(SubmitLeaveRequest::class)->handle($actor, perizinanData($f, $overrides)), $fields);
        } catch (Throwable $exception) {
            throw new RuntimeException("Kasus '{$label}': ".$exception->getMessage(), previous: $exception);
        }
    }

    expect(perizinanCount())->toBe(3)->and(perizinanActivities())->toBeEmpty();
});

it('AC-4: accepts the boundaries and a new request after the previous ones are finished', function () {
    $f = $this->f;

    $longReason = perizinanSubmit($f, $f->kes, ['reason' => str_repeat('a', 1000)]);
    $sixHours = perizinanSubmit($f, $f->kes, ['student_id' => $f->s2->id, 'return_due' => '2026-10-15 19:00']);
    $pulang = perizinanSubmit($f, $f->kes, [
        'student_id' => $f->s4->id, 'leave_type_id' => $f->pulang->id,
        'planned_departure' => '2026-10-16 08:00', 'return_due' => '2026-11-30 17:00', 'pickup_name' => 'Bapak Ahmad',
    ]);

    expect($longReason->reason)->toHaveLength(1000)->and($sixHours->exists)->toBeTrue()->and($pulang->exists)->toBeTrue();

    foreach (['kembali', 'batal', 'ditolak'] as $status) {
        $student = perizinanResident($f);
        perizinanRaw($student, $f->keluar, $status);

        $again = perizinanSubmit($f, $f->kes, ['student_id' => $student->id]);

        expect(perizinanValue($again->status))->toBe('diajukan', "setelah {$status}");
    }
});

it('AC-6: records who decided and when, with from and to in the activity', function () {
    $f = $this->f;
    $approved = perizinanRaw($f->s1, $f->keluar);
    $rejected = perizinanRaw($f->s2, $f->keluar);

    $this->actingAs($f->mDorm);
    app(DecideLeaveRequest::class)->handle($approved, $f->mDorm, true, 'Silakan berangkat');
    app(DecideLeaveRequest::class)->handle($rejected, $f->mDorm, false, 'Belum waktunya');

    $yes = perizinanFresh($approved);
    $no = perizinanFresh($rejected);

    expect(perizinanValue($yes->status))->toBe('disetujui')
        ->and($yes->approved_by)->toBe($f->mDorm->id)
        ->and($yes->approved_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($yes->note)->toBe('Silakan berangkat')
        ->and(perizinanValue($no->status))->toBe('ditolak')
        ->and($no->approved_by)->toBe($f->mDorm->id)
        ->and($no->approved_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($no->note)->toBe('Belum waktunya');

    $granted = perizinanActivities('disetujui')->sole();
    $denied = perizinanActivities('ditolak')->sole();

    expect($granted->subject_id)->toBe($approved->id)
        ->and($granted->causer_id)->toBe($f->mDorm->id)
        ->and($granted->properties['from'])->toBe('diajukan')
        ->and($granted->properties['to'])->toBe('disetujui')
        ->and($denied->subject_id)->toBe($rejected->id)
        ->and($denied->properties['to'])->toBe('ditolak');
});

it('AC-6: refuses to decide for someone who is not the approver', function () {
    $f = $this->f;
    $request = perizinanRaw($f->s1, $f->keluar);

    foreach ([$f->pos, $f->bendahara] as $actor) {
        $this->actingAs($actor);

        expect(fn () => app(DecideLeaveRequest::class)->handle($request, $actor, true, null))->toThrow(AuthorizationException::class);
    }

    foreach ([$f->mB, $f->mOther, $f->head, $f->idle] as $actor) {
        $this->actingAs($actor);

        perizinanDenied(fn () => app(DecideLeaveRequest::class)->handle($request, $actor, true, null));
    }

    $row = perizinanFresh($request);

    expect(perizinanValue($row->status))->toBe('diajukan')->and($row->approved_by)->toBeNull()->and($row->approved_at)->toBeNull()
        ->and(perizinanActivities())->toBeEmpty();
});

it('AC-6 AC-7 AC-8: only allows the status transitions of the plan and leaves everything else untouched', function () {
    $f = $this->f;
    $admin = $f->admin;
    $statuses = ['diajukan', 'disetujui', 'ditolak', 'keluar', 'kembali', 'batal'];

    $actions = [
        'setujui' => [fn (LeaveRequest $r) => app(DecideLeaveRequest::class)->handle($r, $admin, true, null), ['diajukan' => 'disetujui']],
        'tolak' => [fn (LeaveRequest $r) => app(DecideLeaveRequest::class)->handle($r, $admin, false, 'Tidak diizinkan'), ['diajukan' => 'ditolak']],
        'catat keluar' => [fn (LeaveRequest $r) => app(CheckOutLeaveRequest::class)->handle($r, $admin), ['disetujui' => 'keluar']],
        'catat kembali' => [fn (LeaveRequest $r) => app(CheckInLeaveRequest::class)->handle($r, $admin), ['keluar' => 'kembali']],
        'batalkan' => [fn (LeaveRequest $r) => app(CancelLeaveRequest::class)->handle($r, $admin, 'Dibatalkan'), ['diajukan' => 'batal', 'disetujui' => 'batal']],
    ];

    $this->actingAs($admin);

    foreach ($actions as $name => [$run, $allowed]) {
        foreach ($statuses as $status) {
            $request = perizinanRaw($f->s1, $f->keluar, $status);
            $label = "{$name} dari {$status}";

            if (isset($allowed[$status])) {
                $run($request);

                expect(perizinanValue(perizinanFresh($request)->status))->toBe($allowed[$status], $label);

                continue;
            }

            try {
                $run($request);
            } catch (ValidationException) {
                expect(perizinanValue(perizinanFresh($request)->status))->toBe($status, $label);

                continue;
            }

            test()->fail("{$label}: ValidationException diharapkan.");
        }
    }
});

it('AC-7: lets petugas pos record leaving and returning, with who and when', function () {
    $f = $this->f;
    $request = perizinanRaw($f->s1, $f->keluar, 'disetujui');

    $this->actingAs($f->pos);
    app(CheckOutLeaveRequest::class)->handle($request, $f->pos);

    $out = perizinanFresh($request);

    expect(perizinanValue($out->status))->toBe('keluar')
        ->and($out->departed_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($out->checked_out_by)->toBe($f->pos->id)
        ->and($out->returned_at)->toBeNull();

    $this->travel(3)->hours();
    app(CheckInLeaveRequest::class)->handle($out, $f->pos);

    $back = perizinanFresh($request);

    expect(perizinanValue($back->status))->toBe('kembali')
        ->and($back->returned_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($back->checked_in_by)->toBe($f->pos->id)
        ->and($back->departed_at->toDateTimeString())->toBe($out->departed_at->toDateTimeString())
        ->and(perizinanActivities('keluar')->sole()->properties['from'])->toBe('disetujui')
        ->and(perizinanActivities('kembali')->sole()->properties['to'])->toBe('kembali');
});

it('AC-7: refuses musyrif and bendahara at the gate', function () {
    $f = $this->f;
    $approved = perizinanRaw($f->s1, $f->keluar, 'disetujui');
    $out = perizinanRaw($f->s2, $f->keluar, 'keluar');

    foreach ([$f->mA, $f->mDorm, $f->bendahara] as $actor) {
        $this->actingAs($actor);

        expect(fn () => app(CheckOutLeaveRequest::class)->handle($approved, $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CheckInLeaveRequest::class)->handle($out, $actor))->toThrow(AuthorizationException::class);
    }

    expect(perizinanValue(perizinanFresh($approved)->status))->toBe('disetujui')
        ->and(perizinanValue(perizinanFresh($out)->status))->toBe('keluar')
        ->and(perizinanFresh($approved)->checked_out_by)->toBeNull();
});

it('AC-8: cancels with an activity for those who may cancel and refuses the others', function () {
    $f = $this->f;
    $waiting = perizinanRaw($f->s1, $f->keluar);
    $approved = perizinanRaw($f->s2, $f->keluar, 'disetujui');

    $this->actingAs($f->pos);
    expect(fn () => app(CancelLeaveRequest::class)->handle($approved, $f->pos, null))->toThrow(AuthorizationException::class);

    $this->actingAs($f->mB);
    perizinanDenied(fn () => app(CancelLeaveRequest::class)->handle($waiting, $f->mB, null));

    expect(perizinanValue(perizinanFresh($waiting)->status))->toBe('diajukan')
        ->and(perizinanValue(perizinanFresh($approved)->status))->toBe('disetujui');

    $this->actingAs($f->mA);
    app(CancelLeaveRequest::class)->handle($waiting, $f->mA, 'Santri sakit');

    $this->actingAs($f->kes);
    app(CancelLeaveRequest::class)->handle($approved, $f->kes, null);

    $cancelled = perizinanActivities('batal');

    expect(perizinanValue(perizinanFresh($waiting)->status))->toBe('batal')
        ->and(perizinanFresh($waiting)->note)->toBe('Santri sakit')
        ->and(perizinanValue(perizinanFresh($approved)->status))->toBe('batal')
        ->and($cancelled)->toHaveCount(2)
        ->and($cancelled->pluck('properties')->map(fn ($p): array => [$p['from'], $p['to']])->all())->toBe([['diajukan', 'batal'], ['disetujui', 'batal']]);
});

it('AC-9: computes lateness from the clock and never stores it', function () {
    $f = $this->f;
    $now = now();

    $cases = [
        'keluar lewat batas' => [perizinanRaw($f->s1, $f->keluar, 'keluar', ['return_due' => $now->copy()->subHour()]), true],
        'keluar belum lewat batas' => [perizinanRaw($f->s2, $f->keluar, 'keluar', ['return_due' => $now->copy()->addHour()]), false],
        'keluar tepat di batas' => [perizinanRaw($f->s3, $f->keluar, 'keluar', ['return_due' => $now->copy()]), false],
        'kembali setelah batas' => [perizinanRaw($f->s4, $f->keluar, 'kembali', ['return_due' => '2026-10-15 09:00', 'returned_at' => '2026-10-15 09:30']), true],
        'kembali tepat waktu' => [perizinanRaw($f->s1, $f->keluar, 'kembali', ['return_due' => '2026-10-15 09:00', 'returned_at' => '2026-10-15 08:30']), false],
        'disetujui yang batasnya sudah lewat' => [perizinanRaw($f->s2, $f->keluar, 'disetujui', ['return_due' => $now->copy()->subHour()]), false],
        'diajukan yang batasnya sudah lewat' => [perizinanRaw($f->s3, $f->keluar, 'diajukan', ['return_due' => $now->copy()->subHour()]), false],
    ];

    $lateIds = LeaveRequest::withoutGlobalScopes()->late()->pluck('id')->sort()->values()->all();
    $expected = collect($cases)->filter(fn (array $case): bool => $case[1])->map(fn (array $case): int => $case[0]->id)->sort()->values()->all();

    expect($lateIds)->toBe($expected);

    foreach ($cases as $label => [$request, $isLate]) {
        expect(perizinanFresh($request)->is_late)->toBe($isLate, $label);
    }

    $columns = collect(Schema::getColumnListing('leave_requests'))->filter(fn (string $c): bool => str_contains($c, 'late') || str_contains($c, 'terlambat'));

    expect($columns->all())->toBe([]);
});
