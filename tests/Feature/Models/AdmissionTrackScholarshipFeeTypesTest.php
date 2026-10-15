<?php

use App\Models\FeeType;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

beforeEach(function () {
    $this->f = seleksiFixture();
});

/**
 * Kode jenis biaya penerima beasiswa jalur, urut seperti dikembalikan model.
 *
 * @return list<string>
 */
function seleksiScholarshipCodes($track): array
{
    return $track->fresh()->scholarshipFeeTypes()->pluck('code')->all();
}

it('AC-3 (F011): exposes the mapping as many-to-many relations on both sides', function () {
    $track = $this->f->tahfidzTrack;

    expect($track->feeTypes())->toBeInstanceOf(BelongsToMany::class)
        ->and($track->feeTypes()->pluck('fee_types.code')->sort()->values()->all())->toBe(['SPP', 'UANG_PANGKAL'])
        ->and($this->f->uangPangkal->admissionTracks())->toBeInstanceOf(BelongsToMany::class)
        ->and($this->f->uangPangkal->admissionTracks()->pluck('admission_tracks.code')->all())->toBe(['TAHFIDZ'])
        ->and($track->feeTypes()->first()->pivot->created_at)->not->toBeNull();
});

it('AC-3 (F011): returns the mapped fee types ordered by priority', function () {
    expect(seleksiScholarshipCodes($this->f->tahfidzTrack))->toBe(['UANG_PANGKAL', 'SPP']);
});

it('AC-3 (F011): falls back to every acceptance fee type when nothing is mapped', function () {
    expect(seleksiScholarshipCodes($this->f->yatimTrack))->toBe(['UANG_PANGKAL', 'SERAGAM', 'KITAB']);
});

it('AC-3 (F011): leaves out a mapped fee type that was deactivated', function () {
    $this->f->uangPangkal->update(['is_active' => false]);

    expect(seleksiScholarshipCodes($this->f->tahfidzTrack))->toBe(['SPP']);
});

it('AC-3 (F011): leaves out an inactive acceptance fee type in the fallback', function () {
    $this->f->seragam->update(['is_active' => false]);

    expect(seleksiScholarshipCodes($this->f->yatimTrack))->toBe(['UANG_PANGKAL', 'KITAB']);
});

it('AC-3 (F011): ignores a mapped fee type whose frequency is not allowed', function () {
    seleksiMapFeeType($this->f->tahfidzTrack, $this->f->formulir);
    seleksiMapFeeType($this->f->tahfidzTrack, FeeType::factory()->create(['frequency' => 'manual', 'code' => 'MANUAL1', 'billing_group' => 'MANUAL']));

    expect(seleksiScholarshipCodes($this->f->tahfidzTrack))->toBe(['UANG_PANGKAL', 'SPP']);
});

it('AC-3 (F011): never falls back to registration, monthly or manual fee types', function () {
    expect(seleksiScholarshipCodes($this->f->yatimTrack))->not->toContain('FORMULIR')->not->toContain('SPP');
});
