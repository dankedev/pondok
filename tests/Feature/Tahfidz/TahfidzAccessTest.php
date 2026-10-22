<?php

use App\Actions\Tahfidz\DeleteMemorizationRecord;
use App\Filament\Resources\Halaqahs\Pages\EditHalaqah;
use App\Filament\Resources\Halaqahs\RelationManagers\MembersRelationManager;
use App\Filament\Resources\MemorizationRecords\MemorizationRecordResource;
use App\Filament\Resources\TahfidzExams\TahfidzExamResource;
use App\Filament\Resources\TahfidzTargets\TahfidzTargetResource;
use App\Models\Halaqah;
use App\Models\HalaqahMember;
use App\Models\MemorizationRecord;
use App\Models\TahfidzExam;
use App\Models\TahfidzTarget;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = tahfidzBoot();
    // A mengampu X; Y milik ustadz lain.
    $this->a = tahfidzUstadz();
    [$this->x, $this->studentX] = tahfidzHalaqahWithMember($this->f, $this->a->staff);
    [$this->y, $this->studentY] = tahfidzHalaqahWithMember($this->f);
    $this->recordX = tahfidzRawRecord($this->x, $this->studentX);
    $this->recordY = tahfidzRawRecord($this->y, $this->studentY);
    $this->examX = tahfidzRawExam($this->x, $this->studentX);
    $this->examY = tahfidzRawExam($this->y, $this->studentY);
});

it('AC-11: opens the tahfidz resources for admin-sekolah and limits the teacher to the three they use', function () {
    $urls = ['/admin/halaqahs', '/admin/memorization-records', '/admin/tahfidz-exams', '/admin/tahfidz-targets'];

    foreach ($urls as $url) {
        $this->actingAs(asramaUser('admin-sekolah'))->get($url)->assertOk();
    }

    foreach (array_slice($urls, 0, 3) as $url) {
        $this->actingAs($this->a)->get($url)->assertOk();
    }

    $this->actingAs($this->a)->get('/admin/tahfidz-targets')->assertForbidden();
});

it('AC-11: shows no tahfidz resource or menu to other roles', function (string $role) {
    $user = asramaUser($role);

    foreach (['/admin/halaqahs', '/admin/memorization-records', '/admin/tahfidz-exams', '/admin/tahfidz-targets'] as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }

    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee(__('pondok.navigation.tahfidz'));
})->with(['bendahara', 'musyrif', 'kesantrian', 'admin-konten', 'admin-psb', 'petugas-pos']);

it('AC-10: the visibility scopes show a teacher only their halaqah, setoran and exams', function () {
    expect(Halaqah::query()->visibleTo($this->a)->pluck('id')->all())->toBe([$this->x->id])
        ->and(MemorizationRecord::query()->visibleTo($this->a)->pluck('id')->all())->toBe([$this->recordX->id])
        ->and(TahfidzExam::query()->visibleTo($this->a)->pluck('id')->all())->toBe([$this->examX->id])
        ->and(Halaqah::query()->visibleTo(asramaUser('admin-sekolah'))->count())->toBe(2)
        ->and(MemorizationRecord::query()->visibleTo(asramaUser('admin-sekolah'))->count())->toBe(2);
});

it('AC-10: a teacher cannot record a setoran or an exam for another teacher\'s halaqah', function () {
    expect(fn () => tahfidzRecord($this->a, $this->y, $this->studentY))->toThrow(AuthorizationException::class)
        ->and(fn () => tahfidzExam($this->a, $this->y, $this->studentY, ['juz_from' => 29, 'juz_to' => 29]))->toThrow(AuthorizationException::class)
        ->and(MemorizationRecord::query()->count())->toBe(2)
        ->and(TahfidzExam::query()->count())->toBe(2);

    expect(tahfidzRecord($this->a, $this->x, $this->studentX)->exists)->toBeTrue();
});

it('AC-10: a teacher cannot delete or view another teacher\'s setoran and exams', function () {
    expect(fn () => app(DeleteMemorizationRecord::class)->handle($this->a, $this->recordY))->toThrow(AuthorizationException::class)
        ->and(MemorizationRecord::query()->whereKey($this->recordY->id)->exists())->toBeTrue()
        ->and($this->a->can('view', $this->recordY))->toBeFalse()
        ->and($this->a->can('delete', $this->recordY))->toBeFalse()
        ->and($this->a->can('view', $this->examY))->toBeFalse()
        ->and($this->a->can('view', $this->recordX))->toBeTrue()
        ->and($this->a->can('delete', $this->recordX))->toBeTrue()
        ->and($this->a->can('delete', $this->examX))->toBeFalse();

    app(DeleteMemorizationRecord::class)->handle($this->a, $this->recordX);

    expect(MemorizationRecord::query()->whereKey($this->recordX->id)->exists())->toBeFalse();
});

it('AC-10: the tables of a teacher list only the halaqah they teach', function () {
    $this->actingAs($this->a);

    Livewire::test(tahfidzIndexPage(MemorizationRecordResource::class))
        ->assertCanSeeTableRecords([$this->recordX])->assertCanNotSeeTableRecords([$this->recordY]);
    Livewire::test(tahfidzIndexPage(TahfidzExamResource::class))
        ->assertCanSeeTableRecords([$this->examX])->assertCanNotSeeTableRecords([$this->examY]);
});

it('AC-10: the setoran form of a teacher offers only their halaqah and rejects another', function () {
    $this->actingAs($this->a);

    Livewire::test(tahfidzIndexPage(MemorizationRecordResource::class))
        ->callAction(TestAction::make('create')->table(), tahfidzRecordData($this->y, $this->studentY))
        ->assertHasFormErrors(['halaqah_id']);

    expect(MemorizationRecord::query()->count())->toBe(2);
});

it('AC-10: a teacher gets neither the add nor the end action on the members table', function () {
    $this->actingAs($this->a);
    $member = HalaqahMember::query()->where('halaqah_id', $this->x->id)->sole();

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->x, 'pageClass' => EditHalaqah::class])
        ->assertActionHidden(TestAction::make('add')->table())
        ->assertActionHidden(TestAction::make('end')->table($member));
});

it('AC-12: a user scoped to SLTP sees no SLTA halaqah, member, setoran, exam or target, but still sees cross-level halaqah', function () {
    $slta = tahfidzStudent($this->f, 'L', $this->f->slta);
    $haSlta = tahfidzHalaqah($this->f, null, ['unit_id' => $this->f->slta->id]);
    $haAll = tahfidzHalaqah($this->f, null, ['unit_id' => null]);
    $memberSlta = tahfidzMember($haSlta, $slta);
    $recordSlta = tahfidzRawRecord($haSlta, $slta);
    $examSlta = tahfidzRawExam($haSlta, $slta);
    $targetSlta = TahfidzTarget::query()->forceCreate(['academic_year_id' => $this->f->year->id, 'unit_id' => $this->f->slta->id, 'grade' => null, 'target_juz' => 1]);
    $targetSltp = TahfidzTarget::query()->forceCreate(['academic_year_id' => $this->f->year->id, 'unit_id' => $this->f->sltp->id, 'grade' => null, 'target_juz' => 1]);

    $this->actingAs(asramaUser('admin-sekolah', ['SLTP']));

    expect(Halaqah::query()->pluck('id')->all())->toContain($this->x->id, $this->y->id, $haAll->id)->not->toContain($haSlta->id)
        ->and(HalaqahMember::query()->pluck('id')->all())->not->toContain($memberSlta->id)
        ->and(HalaqahMember::query()->count())->toBe(2)
        ->and(MemorizationRecord::query()->pluck('id')->all())->not->toContain($recordSlta->id)
        ->and(MemorizationRecord::query()->count())->toBe(2)
        ->and(TahfidzExam::query()->pluck('id')->all())->not->toContain($examSlta->id)
        ->and(TahfidzExam::query()->count())->toBe(2)
        ->and(TahfidzTarget::query()->pluck('id')->all())->toBe([$targetSltp->id])
        ->and(TahfidzTarget::query()->whereKey($targetSlta->id)->exists())->toBeFalse();

    Livewire::test(tahfidzIndexPage(TahfidzTargetResource::class))
        ->assertCanSeeTableRecords([$targetSltp])->assertCanNotSeeTableRecords([$targetSlta]);
});
