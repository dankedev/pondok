<?php

use App\Enums\LeaveRequestStatus;
use App\Filament\Resources\HomecomingPeriods\HomecomingPeriodResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Pages\CreateLeaveRequest;
use App\Filament\Widgets\OverdueLeavesWidget;
use App\Models\LeaveRequest;
use App\Models\Setting;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = perizinanFixture();
});

function perizinanList(): Testable
{
    return Livewire::test(tahfidzIndexPage(LeaveRequestResource::class));
}

function perizinanWidgetText(User $user): string
{
    test()->actingAs($user);

    return bayarHtmlText(Livewire::test(OverdueLeavesWidget::class)->html());
}

it('AC-14: filters by status, lateness, dormitory and "waiting for my decision"', function () {
    $f = $this->f;
    $late = perizinanRaw($f->s1, $f->keluar, 'keluar', ['return_due' => '2026-10-15 09:00']);
    $onTime = perizinanRaw($f->s2, $f->keluar, 'keluar', ['return_due' => '2026-10-15 17:00']);
    $waiting = perizinanRaw($f->s3, $f->keluar);
    $other = perizinanRaw($f->s4, $f->keluar, 'disetujui');
    $mineWaiting = perizinanRaw($f->s1, $f->keluar);
    $notMineWaiting = perizinanRaw($f->s1, $f->pulang);

    $this->actingAs($f->kes);

    perizinanList()
        ->filterTable('status', 'keluar')
        ->assertCanSeeTableRecords([$late, $onTime])->assertCanNotSeeTableRecords([$waiting, $other, $mineWaiting])
        ->resetTableFilters()
        ->filterTable('late', true)
        ->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$onTime, $waiting, $other])
        ->resetTableFilters()
        ->filterTable('dormitory', $f->dorm2->id)
        ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$late, $onTime, $waiting]);

    $this->actingAs($f->mA);

    perizinanList()
        ->assertCanSeeTableRecords([$mineWaiting, $notMineWaiting])
        ->filterTable('pending_for_me', true)
        ->assertCanSeeTableRecords([$mineWaiting])->assertCanNotSeeTableRecords([$notMineWaiting, $late]);
});

it('AC-14: shows the student, the Indonesian dates, the status label and a late badge only when late', function () {
    $f = $this->f;
    $late = perizinanRaw($f->s1, $f->keluar, 'keluar', ['planned_departure' => '2026-10-15 08:00', 'return_due' => '2026-10-15 09:00']);
    $this->actingAs($f->kes);

    $text = tagihanPlainText(perizinanList());

    expect($text)->toContain('Santri Satu')->toContain($f->s1->nis)
        ->toContain('15 Okt 2026, 08.00')->toContain('15 Okt 2026, 09.00')
        ->toContain(LeaveRequestStatus::from('keluar')->label())
        ->toContain('Terlambat');

    $late->forceFill(['return_due' => '2026-10-15 17:00'])->save();

    expect(tagihanPlainText(perizinanList()))->not->toContain('Terlambat');
});

it('AC-14: maps statuses to the design-guide colours and Indonesian labels', function () {
    $colors = ['diajukan' => 'warning', 'disetujui' => 'success', 'ditolak' => 'danger', 'keluar' => 'info', 'kembali' => 'success', 'batal' => 'gray'];

    foreach ($colors as $value => $color) {
        $status = LeaveRequestStatus::from($value);

        expect($status->color())->toBe($color, $value)
            ->and($status->label())->toBeString()->not->toBe('')->not->toContain('pondok.');
    }

    expect(LeaveRequestStatus::from('diajukan')->label())->toBe('Menunggu')
        ->and(LeaveRequestStatus::from('keluar')->label())->toBe('Di luar')
        ->and(LeaveRequestStatus::from('kembali')->label())->toBe('Sudah kembali');
});

it('AC-14: offers decide actions only to the approver and pos actions only for the right status', function () {
    $f = $this->f;
    $mine = perizinanRaw($f->s1, $f->keluar);
    $notMine = perizinanRaw($f->s1, $f->pulang);
    $approved = perizinanRaw($f->s2, $f->keluar, 'disetujui');
    $out = perizinanRaw($f->s4, $f->keluar, 'keluar');

    $this->actingAs($f->mA);
    perizinanList()
        ->assertTableActionVisible('approve', $mine)->assertTableActionVisible('reject', $mine)
        ->assertTableActionHidden('approve', $notMine)->assertTableActionHidden('reject', $notMine)
        ->assertTableActionVisible('cancel', $mine)
        ->assertTableActionHidden('checkOut', $mine);

    $this->actingAs($f->pos);
    perizinanList()
        ->assertTableActionVisible('checkOut', $approved)->assertTableActionHidden('checkIn', $approved)
        ->assertTableActionVisible('checkIn', $out)->assertTableActionHidden('checkOut', $out)
        ->assertTableActionHidden('approve', $approved)->assertTableActionHidden('cancel', $approved);

    $this->actingAs($f->kes);
    perizinanList()
        ->assertTableActionVisible('cancel', $approved)->assertTableActionHidden('cancel', $out)
        ->assertTableActionHidden('approve', $approved);
});

it('AC-14: runs the row actions through the Actions and requires a note to reject', function () {
    $f = $this->f;
    $first = perizinanRaw($f->s1, $f->keluar);
    $second = perizinanRaw($f->s2, $f->keluar);

    $this->actingAs($f->mDorm);
    perizinanList()
        ->callTableAction('approve', $first)
        ->callTableAction('reject', $second, ['note' => ''])
        ->assertHasTableActionErrors(['note']);

    expect(perizinanValue(perizinanFresh($first)->status))->toBe('disetujui')
        ->and(perizinanFresh($first)->approved_by)->toBe($f->mDorm->id)
        ->and(perizinanValue(perizinanFresh($second)->status))->toBe('diajukan');

    perizinanList()->callTableAction('reject', $second, ['note' => 'Belum waktunya'])->assertHasNoTableActionErrors();

    expect(perizinanValue(perizinanFresh($second)->status))->toBe('ditolak')->and(perizinanFresh($second)->note)->toBe('Belum waktunya');

    $this->actingAs($f->pos);
    perizinanList()->callTableAction('checkOut', $first);

    expect(perizinanValue(perizinanFresh($first)->status))->toBe('keluar')->and(perizinanFresh($first)->checked_out_by)->toBe($f->pos->id);

    perizinanList()->callTableAction('checkIn', perizinanFresh($first));

    expect(perizinanValue(perizinanFresh($first)->status))->toBe('kembali')->and(perizinanFresh($first)->checked_in_by)->toBe($f->pos->id);

    $waiting = perizinanRaw($f->s1, $f->keluar);
    $this->actingAs($f->kes);
    perizinanList()->callTableAction('cancel', $waiting);

    expect(perizinanValue(perizinanFresh($waiting)->status))->toBe('batal');
});

it('AC-14: creates a request through the form via the Action with the origin defaulting to musyrif', function () {
    $f = $this->f;
    $this->actingAs($f->mDorm);

    Livewire::test(CreateLeaveRequest::class)
        ->assertFormSet(['requested_via' => 'musyrif'])
        ->fillForm([
            'student_id' => $f->s1->id,
            'leave_type_id' => $f->keluar->id,
            'reason' => 'Berobat ke dokter gigi',
            'planned_departure' => '2026-10-15 13:00',
            'return_due' => '2026-10-15 17:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = LeaveRequest::query()->sole();

    expect(perizinanValue($request->status))->toBe('diajukan')
        ->and($request->requested_by)->toBe($f->mDorm->id)
        ->and(perizinanValue($request->requested_via))->toBe('musyrif')
        ->and(perizinanActivities('created'))->toHaveCount(1);
});

it('AC-14: creates nothing when the Action rejects the form input', function () {
    $f = $this->f;
    $this->actingAs($f->kes);

    Livewire::test(CreateLeaveRequest::class)
        ->fillForm([
            'student_id' => $f->s1->id,
            'leave_type_id' => $f->keluar->id,
            'reason' => 'Berobat',
            'planned_departure' => '2026-10-15 13:00',
            'return_due' => '2026-10-15 12:00',
        ])
        ->call('create');

    expect(perizinanCount())->toBe(0);
});

it('AC-2 AC-4: offers only active leave types and, to a musyrif, only supervised residents in the form', function () {
    $f = $this->f;
    $f->sakit->forceFill(['is_active' => false])->save();
    $this->actingAs($f->mDorm);

    $component = Livewire::test(CreateLeaveRequest::class);
    $types = perizinanSelectOptions($component, 'leave_type_id');
    $students = perizinanSelectOptions($component, 'student_id', 'Santri');

    expect($types)->toContain($f->keluar->id, $f->pulang->id, $f->keperluan->id)->not->toContain($f->sakit->id)
        ->and($students)->toContain($f->s1->id, $f->s2->id)->not->toContain($f->s3->id, $f->s4->id);
});

it('AC-10: generates the mass leaves from the period table and reports both numbers', function () {
    $f = $this->f;
    $period = perizinanPeriod($f);
    perizinanRaw($f->s2, $f->keluar, 'keluar');
    $this->actingAs($f->kes);

    Livewire::test(tahfidzIndexPage(HomecomingPeriodResource::class))
        ->callTableAction('generate', $period);

    $notification = json_encode(asramaNotifications()->all());

    expect(LeaveRequest::withoutGlobalScopes()->where('homecoming_period_id', $period->id)->count())->toBe(2)
        ->and($notification)->toContain('2')->toContain('1');
});

it('AC-15: shows the overdue count and the top five names only to those who may see them', function () {
    $f = $this->f;

    foreach ([$f->s1, $f->s2, $f->s4] as $student) {
        perizinanRaw($student, $f->keluar, 'keluar', ['return_due' => '2026-10-15 09:00']);
    }

    perizinanRaw(perizinanResident($f), $f->keluar, 'keluar', ['return_due' => '2026-10-15 17:00']);
    perizinanRaw(perizinanResident($f), $f->keluar, 'kembali', ['return_due' => '2026-10-15 08:00', 'returned_at' => '2026-10-15 09:00']);
    perizinanRaw(perizinanResident($f), $f->keluar, 'disetujui', ['return_due' => '2026-10-15 08:00']);

    $all = perizinanWidgetText($f->kes);

    expect($all)->toContain('Santri Satu')->toContain('Santri Dua')->toContain('Santri Empat')
        ->and(preg_match('/\b3\b/', $all))->toBe(1)
        ->and(perizinanWidgetText($f->mA))->toContain('Santri Satu')->not->toContain('Santri Dua')->not->toContain('Santri Empat')
        ->and(perizinanWidgetText($f->kesSltp))->toContain('Santri Dua')->not->toContain('Santri Empat')
        ->and(perizinanWidgetText($f->pos))->toContain('Santri Empat')
        ->and(Livewire::test(OverdueLeavesWidget::class)->html())->toContain(url('/admin/leave-requests'));
});

it('AC-15: lists at most five names however many are overdue', function () {
    $f = $this->f;

    $names = ['Alfa', 'Beta', 'Gama', 'Delta', 'Epsilon', 'Zeta', 'Eta'];

    foreach ($names as $name) {
        $student = perizinanResident($f, null, ['name' => "Terlambat {$name}"]);
        perizinanRaw($student, $f->keluar, 'keluar', ['return_due' => '2026-10-15 09:00']);
    }

    $text = perizinanWidgetText($f->kes);

    expect(preg_match_all('/Terlambat (Alfa|Beta|Gama|Delta|Epsilon|Zeta|Eta)/', $text))->toBe(5)
        ->and(preg_match('/\b7\b/', $text))->toBe(1);
});

it('AC-15: shows the widget to kesantrian, musyrif and petugas pos but not to bendahara, and hides data when the module is off', function () {
    $f = $this->f;
    perizinanRaw($f->s1, $f->keluar, 'keluar', ['return_due' => '2026-10-15 09:00']);

    foreach ([$f->kes, $f->mA, $f->pos] as $user) {
        $this->actingAs($user);

        expect(OverdueLeavesWidget::canView())->toBeTrue();
    }

    $this->actingAs($f->bendahara);

    expect(OverdueLeavesWidget::canView())->toBeFalse();

    Setting::set('modules.perizinan.enabled', false);

    expect(OverdueLeavesWidget::isModuleAvailable())->toBeFalse()
        ->and(perizinanWidgetText($f->kes))->toContain(__('pondok.dashboard.module_unavailable'))->not->toContain('Santri Satu');
});
