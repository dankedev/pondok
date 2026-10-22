<?php

use App\Actions\Tahfidz\AddHalaqahMember;
use App\Actions\Tahfidz\EndHalaqahMembership;
use App\Filament\Resources\Halaqahs\Pages\EditHalaqah;
use App\Filament\Resources\Halaqahs\RelationManagers\MembersRelationManager;
use App\Filament\Resources\MemorizationRecords\MemorizationRecordResource;
use App\Filament\Resources\TahfidzExams\TahfidzExamResource;
use App\Models\HalaqahMember;
use App\Models\MemorizationRecord;
use App\Models\TahfidzExam;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = tahfidzBoot();
    $this->a = tahfidzUstadz();
    [$this->x, $this->studentX] = tahfidzHalaqahWithMember($this->f, $this->a->staff);
    [$this->y, $this->studentY] = tahfidzHalaqahWithMember($this->f);
});

it('VERIFY: a teacher cannot open the edit page or the members table of another teacher\'s halaqah', function () {
    $this->actingAs($this->a)->get("/admin/halaqahs/{$this->y->id}/edit")->assertStatus(404);

    $this->actingAs($this->a);

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->y, 'pageClass' => EditHalaqah::class])
        ->assertDontSee($this->studentY->name);

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->x, 'pageClass' => EditHalaqah::class])
        ->assertSee($this->studentX->name);
});

it('VERIFY: forging the record of another halaqah in the delete table action deletes nothing', function () {
    $recordY = tahfidzRawRecord($this->y, $this->studentY);
    $examY = tahfidzRawExam($this->y, $this->studentY);

    $this->actingAs(asramaUser('admin-sekolah', ['SLTA']));
    $slta = tahfidzStudent($this->f, 'L', $this->f->slta);
    $haSlta = tahfidzHalaqah($this->f, null, ['unit_id' => $this->f->slta->id]);
    tahfidzMember($haSlta, $slta);
    $recordSlta = tahfidzRawRecord($haSlta, $slta);

    // Admin ber-cakupan SLTA mencoba menghapus setoran SLTP (bukan miliknya) lewat id.
    try {
        Livewire::test(tahfidzIndexPage(MemorizationRecordResource::class))
            ->callAction(TestAction::make('delete')->table($recordY));
    } catch (Throwable) {
    }

    // Ustadz mencoba menghapus setoran halaqah lain.
    $this->actingAs($this->a);
    try {
        Livewire::test(tahfidzIndexPage(MemorizationRecordResource::class))
            ->callAction(TestAction::make('delete')->table($recordY));
    } catch (Throwable) {
    }
    try {
        Livewire::test(tahfidzIndexPage(TahfidzExamResource::class))
            ->callAction(TestAction::make('delete')->table($examY));
    } catch (Throwable) {
    }

    expect(MemorizationRecord::query()->withoutGlobalScopes()->whereKey($recordY->id)->exists())->toBeTrue()
        ->and(TahfidzExam::query()->withoutGlobalScopes()->whereKey($examY->id)->exists())->toBeTrue()
        ->and(MemorizationRecord::query()->withoutGlobalScopes()->whereKey($recordSlta->id)->exists())->toBeTrue();
});

it('VERIFY: the setoran form refuses a student of another halaqah forged into the submitted halaqah', function () {
    $this->actingAs($this->a);

    Livewire::test(tahfidzIndexPage(MemorizationRecordResource::class))
        ->callAction(TestAction::make('create')->table(), tahfidzRecordData($this->x, $this->studentY));

    expect(MemorizationRecord::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('VERIFY: a user scoped to another level cannot record in an out-of-scope halaqah', function () {
    $scoped = asramaUser('admin-sekolah', ['SLTA']);
    $this->actingAs($scoped);

    expect(fn () => tahfidzRecord($scoped, $this->y, $this->studentY))->toThrow(Exception::class)
        ->and(MemorizationRecord::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('VERIFY: a certificate number with surrounding spaces still counts as a duplicate', function () {
    $admin = tahfidzAdmin();
    tahfidzExam($admin, $this->x, $this->studentX, ['certificate_no' => 'SYH-001']);
    $other = tahfidzStudent($this->f);
    tahfidzMember($this->x, $other);

    tahfidzRejected(fn () => tahfidzExam($admin, $this->x, $other, ['certificate_no' => ' SYH-001 ']), 'certificate_no');
    expect(TahfidzExam::query()->count())->toBe(1);
});

it('VERIFY: a setoran on the left_on date set by EndHalaqahMembership is rejected, the day before accepted', function () {
    $admin = tahfidzAdmin();
    $member = HalaqahMember::query()->where('student_id', $this->studentX->id)->sole();
    app(EndHalaqahMembership::class)->handle($admin, $member, CarbonImmutable::parse('2026-10-15'));

    expect(tahfidzRecord($admin, $this->x, $this->studentX, ['recorded_on' => '2026-10-14'])->exists)->toBeTrue();
    tahfidzRejected(fn () => tahfidzRecord($admin, $this->x, $this->studentX, ['recorded_on' => '2026-10-15']), ['recorded_on', 'student_id']);
});

it('VERIFY: a student who moved halaqah can only be recorded in the halaqah of that date', function () {
    $admin = tahfidzAdmin();
    $b = tahfidzHalaqah($this->f);
    app(AddHalaqahMember::class)->handle($admin, $b, $this->studentX, CarbonImmutable::parse('2026-10-10'));

    expect(tahfidzRecord($admin, $this->x, $this->studentX, ['recorded_on' => '2026-10-09'])->exists)->toBeTrue()
        ->and(tahfidzRecord($admin, $b, $this->studentX, ['recorded_on' => '2026-10-11'])->exists)->toBeTrue();
    tahfidzRejected(fn () => tahfidzRecord($admin, $this->x, $this->studentX, ['recorded_on' => '2026-10-11']), ['student_id']);
    tahfidzRejected(fn () => tahfidzRecord($admin, $b, $this->studentX, ['recorded_on' => '2026-10-09']), ['student_id']);
});

it('VERIFY: an archived student cannot receive a setoran', function () {
    $this->studentX->delete();

    tahfidzRejected(fn () => tahfidzRecord(tahfidzAdmin(), $this->x, $this->studentX), ['student_id']);
});

it('VERIFY: 1:1-2:141 closes only juz 1 and 1:1-2:142 starts juz 2 as proses', function () {
    $this->halaqah = $this->x;
    $this->student = $this->studentX;
    tahfidzRawRecord($this->x, $this->studentX, ['recorded_on' => '2026-10-01', 'from_surah_id' => 1, 'from_ayah' => 1, 'to_surah_id' => 2, 'to_ayah' => 141]);
    tahfidzRefresh($this->studentX);

    expect(tahfidzProgress($this->studentX))->toBe([1 => ['hafal', '2026-10-01']]);

    tahfidzRawRecord($this->x, $this->studentX, ['recorded_on' => '2026-10-02', 'from_surah_id' => 2, 'from_ayah' => 142, 'to_surah_id' => 2, 'to_ayah' => 142]);
    tahfidzRefresh($this->studentX);

    expect(tahfidzProgress($this->studentX))->toBe([1 => ['hafal', '2026-10-01'], 2 => ['proses', null]]);
});

it('VERIFY: a passed exam before the setoran keeps lulus_ujian and takes the hafal date once the setoran closes the juz', function () {
    $admin = tahfidzAdmin();
    tahfidzExam($admin, $this->x, $this->studentX, ['exam_on' => '2026-10-05']);
    expect(tahfidzProgress($this->studentX))->toBe([30 => ['lulus_ujian', '2026-10-05']]);

    tahfidzRecord($admin, $this->x, $this->studentX, ['recorded_on' => '2026-10-10', 'from_surah_id' => 78, 'from_ayah' => 1, 'to_surah_id' => 114, 'to_ayah' => 6]);

    expect(tahfidzProgress($this->studentX))->toBe([30 => ['lulus_ujian', '2026-10-10']]);
});

it('VERIFY: a failed exam with a certificate number is rejected', function () {
    tahfidzRejected(fn () => tahfidzExam(tahfidzAdmin(), $this->x, $this->studentX, ['result' => 'ulang', 'certificate_no' => 'X-1']), 'certificate_no');
});
