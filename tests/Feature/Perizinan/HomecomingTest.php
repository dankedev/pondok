<?php

use App\Actions\Perizinan\GenerateHomecomingLeaves;
use App\Actions\Perizinan\SaveHomecomingPeriod;
use App\Models\HomecomingPeriod;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->f = perizinanFixture();
});

/**
 * @return array{created: int, skipped: int}
 */
function perizinanGenerate(object $f, HomecomingPeriod $period, ?User $by = null): array
{
    $by ??= $f->kes;
    test()->actingAs($by);

    return app(GenerateHomecomingLeaves::class)->handle($period, $by);
}

/**
 * Izin milik periode, per santri.
 *
 * @return Collection<int, LeaveRequest>
 */
function perizinanPeriodLeaves(HomecomingPeriod $period): Collection
{
    return LeaveRequest::withoutGlobalScopes()->where('homecoming_period_id', $period->id)->get()->keyBy('student_id');
}

it('AC-10: gives every active resident an approved PULANG leave and skips the others', function () {
    $f = $this->f;
    $period = perizinanPeriod($f, ['name' => 'Libur Semester Ganjil']);
    $graduate = tahfidzStudent($f, 'L', null, ['status' => 'lulus']);
    asramaResident($graduate, $f->roomA);
    perizinanRaw($f->s2, $f->keluar, 'keluar');

    $result = perizinanGenerate($f, $period);

    $rows = perizinanPeriodLeaves($period);

    expect($result)->toBe(['created' => 2, 'skipped' => 1])
        ->and($rows->keys()->sort()->values()->all())->toBe(collect([$f->s1->id, $f->s4->id])->sort()->values()->all());

    foreach ($rows as $row) {
        expect($row->leave_type_id)->toBe($f->pulang->id)
            ->and(perizinanValue($row->status))->toBe('disetujui')
            ->and($row->approved_by)->toBe($f->kes->id)
            ->and(perizinanValue($row->requested_via))->toBe('musyrif')
            ->and($row->reason)->toBe('Libur Semester Ganjil')
            ->and($row->planned_departure->format('Y-m-d H:i:s'))->toBe('2026-10-20 00:00:00')
            ->and($row->return_due->format('Y-m-d H:i:s'))->toBe('2026-10-27 23:59:59');
    }

    expect(perizinanValue(perizinanFresh(LeaveRequest::withoutGlobalScopes()->where('student_id', $f->s2->id)->sole())->status))->toBe('keluar');
});

it('AC-10: is idempotent and picks up the skipped and the new residents on a later run', function () {
    $f = $this->f;
    $period = perizinanPeriod($f);
    $busy = perizinanRaw($f->s2, $f->keluar, 'keluar');

    perizinanGenerate($f, $period);
    $afterFirst = LeaveRequest::withoutGlobalScopes()->count();

    $second = perizinanGenerate($f, $period);

    expect($second['created'])->toBe(0)->and(LeaveRequest::withoutGlobalScopes()->count())->toBe($afterFirst);

    $busy->forceFill(['status' => 'kembali', 'returned_at' => '2026-10-15 09:30'])->save();
    $newcomer = perizinanResident($f);

    $third = perizinanGenerate($f, $period);

    expect($third['created'])->toBe(2)
        ->and(perizinanPeriodLeaves($period)->keys()->sort()->values()->all())
        ->toBe(collect([$f->s1->id, $f->s2->id, $f->s4->id, $newcomer->id])->sort()->values()->all())
        ->and(LeaveRequest::withoutGlobalScopes()->count())->toBe($afterFirst + 2);
});

it('AC-10: raises an error without creating rows when the PULANG type is inactive', function () {
    $f = $this->f;
    $period = perizinanPeriod($f);
    $f->pulang->forceFill(['is_active' => false])->save();

    expect(fn () => perizinanGenerate($f, $period))->toThrow(ValidationException::class)
        ->and(LeaveRequest::withoutGlobalScopes()->count())->toBe(0);
});

it('AC-10: refuses musyrif and petugas pos for generating and saving periods', function () {
    $f = $this->f;
    $period = perizinanPeriod($f);
    $data = ['academic_year_id' => $f->year->id, 'name' => 'Libur Baru', 'depart_on' => '2026-12-20', 'return_on' => '2027-01-05'];

    foreach ([$f->mA, $f->pos] as $actor) {
        $this->actingAs($actor);

        expect(fn () => app(GenerateHomecomingLeaves::class)->handle($period, $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveHomecomingPeriod::class)->handle($data, $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveHomecomingPeriod::class)->handle([...$data, 'name' => 'Diubah'], $actor, $period))->toThrow(AuthorizationException::class);
    }

    expect(LeaveRequest::withoutGlobalScopes()->count())->toBe(0)
        ->and(HomecomingPeriod::query()->count())->toBe(1)
        ->and($period->fresh()->name)->toBe('Libur Semester Ganjil');
});

it('AC-10: lets kesantrian save a period and refuses a return date before the departure date', function () {
    $f = $this->f;
    $data = ['academic_year_id' => $f->year->id, 'name' => 'Libur Idul Adha', 'depart_on' => '2027-05-20', 'return_on' => '2027-05-27'];
    $this->actingAs($f->kes);

    $period = app(SaveHomecomingPeriod::class)->handle($data, $f->kes);

    expect($period->fresh()->name)->toBe('Libur Idul Adha')
        ->and($period->fresh()->return_on->toDateString())->toBe('2027-05-27');

    tahfidzRejected(fn () => app(SaveHomecomingPeriod::class)->handle([...$data, 'return_on' => '2027-05-19'], $f->kes), 'return_on');
    tahfidzRejected(fn () => app(SaveHomecomingPeriod::class)->handle([...$data, 'return_on' => '2027-05-19'], $f->kes, $period), 'return_on');

    app(SaveHomecomingPeriod::class)->handle([...$data, 'depart_on' => '2027-05-27'], $f->kes, $period);

    expect(HomecomingPeriod::query()->count())->toBe(1)->and($period->fresh()->return_on->toDateString())->toBe('2027-05-27');
});

it('AC-10: moves the return deadline of approved and outside leaves but not of returned ones', function () {
    $f = $this->f;
    $period = perizinanPeriod($f);
    $other = perizinanResident($f);

    perizinanGenerate($f, $period);

    $rows = perizinanPeriodLeaves($period);
    $rows[$f->s4->id]->forceFill(['status' => 'keluar', 'departed_at' => '2026-10-20 08:00'])->save();
    $rows[$other->id]->forceFill(['status' => 'kembali', 'departed_at' => '2026-10-20 08:00', 'returned_at' => '2026-10-27 09:00'])->save();

    $this->actingAs($f->kes);
    app(SaveHomecomingPeriod::class)->handle([
        'academic_year_id' => $f->year->id, 'name' => $period->name, 'depart_on' => '2026-10-20', 'return_on' => '2026-10-30',
    ], $f->kes, $period);

    $after = perizinanPeriodLeaves($period);

    expect($after[$f->s1->id]->return_due->format('Y-m-d H:i:s'))->toBe('2026-10-30 23:59:59')
        ->and($after[$f->s4->id]->return_due->format('Y-m-d H:i:s'))->toBe('2026-10-30 23:59:59')
        ->and($after[$other->id]->return_due->format('Y-m-d H:i:s'))->toBe('2026-10-27 23:59:59');
});
