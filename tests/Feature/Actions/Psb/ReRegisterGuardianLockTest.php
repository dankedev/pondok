<?php

use App\Actions\Psb\ReRegisterApplicant;
use App\Actions\Psb\ResolveApplicantGuardian;
use App\Models\Applicant;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| F069: daftar ulang diserialkan per nomor HP wali lewat Cache::lock (store `array` di test = satu proses).
| Bukti balapan dua proses (AC-1..AC-3) ada di ReRegisterConcurrencyMysqlTest.
*/

/**
 * Subkelas tanpa waktu tunggu: kunci yang dipegang pihak lain langsung memicu `guardian_busy`.
 */
class ReRegisterApplicantWithoutWait extends ReRegisterApplicant
{
    public const LOCK_WAIT_SECONDS = 0;
}

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

/**
 * Jalankan daftar ulang tanpa waktu tunggu kunci; `$applicant` dipakai apa adanya (boleh basi).
 *
 * @param  array<string, mixed>  $data
 */
function guardianLockRun(Applicant $applicant, array $data = []): array
{
    $by = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'admin-psb'))->oldest('id')->first() ?? psbUser('admin-psb');
    test()->actingAs($by);

    return app(ReRegisterApplicantWithoutWait::class)->handle($applicant, $by, $data);
}

/**
 * Kunci milik "proses lain" untuk HP `$phone` (instance berbeda = pemilik berbeda).
 */
function guardianLockHold(string $phone): void
{
    expect(Cache::lock(ResolveApplicantGuardian::lockKey($phone), 300)->get())->toBeTrue();
}

/**
 * @return array<string, int>
 */
function guardianLockCounts(): array
{
    return [
        'students' => Student::withoutGlobalScopes()->withTrashed()->count(),
        'guardians' => Guardian::query()->count(),
        'pivot' => DB::table('student_guardian')->count(),
        'activities' => Activity::query()->count(),
    ];
}

it('AC-5: releases the guardian lock after success', function () {
    $applicant = daftarUlangApplicant($this->f);

    $result = daftarUlangRun($applicant);

    expect($result['student'])->toBeInstanceOf(Student::class)
        ->and(Cache::lock(ResolveApplicantGuardian::lockKey('081234567890'), 1)->get())->toBeTrue();
});

it('AC-6: releases the guardian lock after failure and rethrows the original error', function () {
    $applicant = daftarUlangApplicant($this->f, paid: 1000000);

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'min_payment_not_met');

    expect(Cache::lock(ResolveApplicantGuardian::lockKey('081234567890'), 1)->get())->toBeTrue();
});

it('AC-7: rejects with guardian_busy when another holder keeps the guardian lock', function () {
    $applicant = daftarUlangApplicant($this->f);
    $before = guardianLockCounts();
    guardianLockHold('081234567890');

    daftarUlangAssertRejected(fn () => guardianLockRun($applicant), 'guardian_busy', 'applicant');

    expect(guardianLockCounts())->toBe($before)
        ->and(Applicant::withoutGlobalScopes()->findOrFail($applicant->id)->isConverted())->toBeFalse()
        ->and(DB::table('invoices')->where('applicant_id', $applicant->id)->whereNotNull('student_id')->count())->toBe(0);
});

it('AC-8: uses one lock for all phone variants', function () {
    expect(ResolveApplicantGuardian::lockKey('6281234567890'))->toBe(ResolveApplicantGuardian::lockKey('081234567890'))
        ->and(ResolveApplicantGuardian::lockKey('+6281234567890'))->toBe(ResolveApplicantGuardian::lockKey('081234567890'));

    $applicant = daftarUlangApplicant($this->f);
    guardianLockHold('+6281234567890');

    daftarUlangAssertRejected(fn () => guardianLockRun($applicant), 'guardian_busy', 'applicant');
});

it('AC-9: does not wait for another guardian phone', function () {
    $applicant = daftarUlangApplicant($this->f);
    guardianLockHold('081298765432');

    $result = guardianLockRun($applicant);

    expect($result['student'])->toBeInstanceOf(Student::class);
});

it('AC-10: validates input before locking', function () {
    $applicant = daftarUlangApplicant($this->f);
    guardianLockHold('081234567890');

    daftarUlangAssertRejected(fn () => guardianLockRun($applicant, ['relation' => 'tetangga']), 'relation_invalid', 'relation');
});

it('AC-11: locks the stored guardian phone, not the stale in-memory one', function () {
    $applicant = daftarUlangApplicant($this->f);
    $stale = Applicant::withoutGlobalScopes()->findOrFail($applicant->id);
    $stale->guardian_phone = '081298765432';

    guardianLockHold('081234567890');
    daftarUlangAssertRejected(fn () => guardianLockRun($stale), 'guardian_busy', 'applicant');

    Cache::lock(ResolveApplicantGuardian::lockKey('081234567890'))->forceRelease();
    guardianLockHold('081298765432');

    expect(guardianLockRun($stale)['student'])->toBeInstanceOf(Student::class);
});

it('AC-12: rejects when the guardian phone changes between reading and locking', function () {
    $applicant = daftarUlangApplicant($this->f);
    $before = guardianLockCounts();
    $changed = false;

    DB::listen(function (QueryExecuted $query) use ($applicant, &$changed): void {
        if (! $changed && preg_match('/^select\s+["`]?guardian_phone["`]?\s+from/i', $query->sql) === 1) {
            $changed = true;
            DB::table('applicants')->where('id', $applicant->id)->update(['guardian_phone' => '081298765432']);
        }
    });

    daftarUlangAssertRejected(fn () => guardianLockRun($applicant), 'guardian_busy', 'applicant');

    expect($changed)->toBeTrue()
        ->and(guardianLockCounts())->toBe($before)
        ->and(Cache::lock(ResolveApplicantGuardian::lockKey('081234567890'), 1)->get())->toBeTrue();
});

it('has a translated guardian_busy message', function () {
    expectTranslatedKeys(['pondok.psb.errors.guardian_busy']);
});
