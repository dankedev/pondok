<?php

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Actions\Dokumen\RedactExpiredLetterSnapshots;
use App\Models\Invoice;
use App\Models\IssuedLetter;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| F042 AC-6, AC-7, AC-8: redaksi data pribadi snapshot surat keluar setelah masa simpan.
| Waktu uji dibekukan pada 2026-10-15.
*/

beforeEach(function () {
    $this->f = suratFixture();
});

/**
 * Terbitkan surat lalu geser tanggal terbitnya (tanpa menyentuh isi).
 *
 * @param  array<string, mixed>  $overrides
 */
function retentionLetter(object $f, string $issuedOn, array $overrides = [], ?Student $student = null): IssuedLetter
{
    $letter = suratIssue($f, $overrides, $student);
    DB::table('issued_letters')->where('id', $letter->id)->update(['issued_on' => $issuedOn]);

    return $letter->fresh();
}

it('AC-6: redacts nothing while no retention period is set', function () {
    $letter = retentionLetter($this->f, '2015-01-01');
    $before = $letter->snapshot;

    expect(app(RedactExpiredLetterSnapshots::class)->handle())->toBe(0);

    Setting::set('retention.letter_snapshot_years', null);
    expect(app(RedactExpiredLetterSnapshots::class)->handle())->toBe(0)
        ->and($letter->fresh()->snapshot)->toBe($before)
        ->and($letter->fresh()->snapshot_redacted_at)->toBeNull();
});

it('AC-6: reduces the student block to the masked name and keeps the rest of the letter, for issued and cancelled letters', function () {
    $f = $this->f;
    Setting::set('retention.letter_snapshot_years', 2);
    $issued = retentionLetter($f, '2023-10-15');
    $cancelled = retentionLetter($f, '2023-10-15');
    app(CancelIssuedLetter::class)->handle($cancelled, $f->admin, 'Salah input');
    $recent = retentionLetter($f, '2025-10-15');
    $recentBefore = $recent->snapshot;
    $before = $issued->snapshot;

    expect(app(RedactExpiredLetterSnapshots::class)->handle())->toBe(2);

    foreach ([[$issued, $issued->fresh()], [$cancelled, $cancelled->fresh()]] as [$original, $letter]) {
        expect($letter->snapshot['student'])->toBe(['name' => 'Muhammad F. A.'])
            ->and($letter->snapshot['signers'])->toBe($before['signers'])
            ->and($letter->snapshot['school'])->toBe($before['school'])
            ->and($letter->snapshot_redacted_at)->not->toBeNull()
            ->and($letter->letter_no)->toBe($original->letter_no)
            ->and($letter->verification_code)->toBe($original->verification_code);
    }

    expect($issued->fresh()->status)->toBe(IssuedLetter::STATUS_ISSUED)
        ->and($issued->fresh()->student_id)->toBe($issued->student_id)
        ->and($cancelled->fresh()->status)->toBe(IssuedLetter::STATUS_CANCELLED)
        ->and($recent->fresh()->snapshot)->toBe($recentBefore)
        ->and($recent->fresh()->snapshot_redacted_at)->toBeNull();
});

it('AC-6: treats the cutoff date as expired and the day after as still retained', function () {
    Setting::set('retention.letter_snapshot_years', 2);
    $onCutoff = retentionLetter($this->f, '2024-10-15');
    $afterCutoff = retentionLetter($this->f, '2024-10-16');

    expect(app(RedactExpiredLetterSnapshots::class)->handle())->toBe(1)
        ->and($onCutoff->fresh()->snapshot_redacted_at)->not->toBeNull()
        ->and($afterCutoff->fresh()->snapshot_redacted_at)->toBeNull();
});

it('AC-6: covers every unit and archived students regardless of the acting user scope', function () {
    $f = $this->f;
    Setting::set('retention.letter_snapshot_years', 2);
    $sd = retentionLetter($f, '2023-01-10', student: $f->s1);
    $sltp = retentionLetter($f, '2023-01-10', student: $f->s2);
    $f->s2->delete();

    $this->actingAs($f->scoped);

    expect(app(RedactExpiredLetterSnapshots::class)->handle())->toBe(2)
        ->and($sd->fresh()->snapshot_redacted_at)->not->toBeNull()
        ->and($sltp->fresh()->snapshot_redacted_at)->not->toBeNull();
});

it('AC-6: redacts through the command even when the Dokumen module is off', function () {
    Setting::set('retention.letter_snapshot_years', 2);
    $letter = retentionLetter($this->f, '2023-01-10');
    Setting::set('modules.dokumen.enabled', false);

    expect(Artisan::call('pondok:redact-letter-snapshots'))->toBe(0)
        ->and($letter->fresh()->snapshot['student'])->toBe(['name' => 'Muhammad F. A.']);
});

it('AC-7: is idempotent, logs one audit entry without personal data and leaves finance rows alone', function () {
    $f = $this->f;
    Setting::set('retention.letter_snapshot_years', 2);
    retentionLetter($f, '2023-01-10');
    retentionLetter($f, '2023-02-10');
    $invoice = Invoice::factory()->create(['student_id' => $f->s1->id]);
    DB::table('invoices')->where('id', $invoice->id)->update(['updated_at' => '2023-01-01 00:00:00']);
    $invoiceBefore = (array) DB::table('invoices')->where('id', $invoice->id)->first();

    expect(app(RedactExpiredLetterSnapshots::class)->handle())->toBe(2);

    $activity = Activity::query()->where('log_name', 'sistem')->where('event', 'redact')->sole();
    $json = (string) json_encode($activity->properties);
    $updatedAt = DB::table('issued_letters')->pluck('updated_at', 'id')->all();

    expect($activity->properties['redacted'])->toBe(2)
        ->and($json)->not->toContain('Muhammad')->not->toContain('0098765432')->not->toContain('2026000123')
        ->and((array) DB::table('invoices')->where('id', $invoice->id)->first())->toBe($invoiceBefore)
        ->and(DB::table('invoices')->count())->toBe(1);

    $this->travel(2)->days();

    expect(app(RedactExpiredLetterSnapshots::class)->handle())->toBe(0)
        ->and(DB::table('issued_letters')->pluck('updated_at', 'id')->all())->toBe($updatedAt)
        ->and(Activity::query()->where('event', 'redact')->count())->toBe(1);
});

it('AC-8: keeps public verification working with the masked name', function () {
    Setting::set('retention.letter_snapshot_years', 2);
    $letter = retentionLetter($this->f, '2023-01-10');
    app(RedactExpiredLetterSnapshots::class)->handle();

    $text = suratText($this->get('/verifikasi-surat/'.$letter->verification_code)->assertOk()->getContent());

    expect($text)->toContain(__('pondok.letter_verification.valid_title'))
        ->toContain($letter->letter_no)
        ->toContain('Muhammad F. A.')
        ->not->toContain('Muhammad Fauzan');
});

it('AC-8: prints a redacted letter and student card with a note and no old personal data', function (string $type) {
    $f = $this->f;
    Setting::set('retention.letter_snapshot_years', 2);
    $letter = retentionLetter($f, '2023-01-10', ['type' => $type]);
    app(RedactExpiredLetterSnapshots::class)->handle();

    $text = suratText($this->actingAs($f->admin)->get("/admin/letters/{$letter->id}/print")->assertOk()->getContent());

    expect($text)->toContain('Data pribadi surat ini telah dihapus sesuai masa simpan')
        ->not->toContain('0098765432')
        ->not->toContain('2026000123')
        ->not->toContain('Bandung')
        ->not->toContain('2 Maret 2014');
})->with(['keterangan_aktif', 'kartu_santri']);
