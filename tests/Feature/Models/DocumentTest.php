<?php

use App\Models\Document;
use App\Models\Guardian;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->f = dokumenFixture();
});

it('AC-4: maps documentable morph aliases', function () {
    expect((new Student)->getMorphClass())->toBe('student')
        ->and((new Guardian)->getMorphClass())->toBe('guardian')
        ->and((new Staff)->getMorphClass())->toBe('staff')
        ->and(Relation::getMorphedModel('student'))->toBe(Student::class)
        ->and(Relation::getMorphedModel('guardian'))->toBe(Guardian::class)
        ->and(Relation::getMorphedModel('staff'))->toBe(Staff::class);
});

it('AC-4: does not enforce the morph map for other models', function () {
    expect((new User)->getMorphClass())->toBe(User::class);
});

it('AC-4: stores the alias and resolves the owner', function () {
    $document = dokumenCreate($this->f->s1, 'KK');

    expect(DB::table('documents')->where('id', $document->id)->value('documentable_type'))->toBe('student')
        ->and($document->documentable)->toBeInstanceOf(Student::class)
        ->and($document->documentable->is($this->f->s1))->toBeTrue();
});

it('AC-4: keeps documents of each owner apart', function () {
    $student = dokumenCreate($this->f->s1, 'KK');
    $other = dokumenCreate($this->f->s2, 'KK');
    $guardian = dokumenCreate($this->f->g1, 'KK');
    $staff = dokumenCreate($this->f->t1, 'KK');

    expect($this->f->s1->documents->pluck('id')->all())->toBe([$student->id])
        ->and($this->f->s2->documents->pluck('id')->all())->toBe([$other->id])
        ->and($this->f->g1->documents->pluck('id')->all())->toBe([$guardian->id])
        ->and($this->f->t1->documents->pluck('id')->all())->toBe([$staff->id])
        ->and($guardian->fresh()->documentable)->toBeInstanceOf(Guardian::class)
        ->and($staff->fresh()->documentable)->toBeInstanceOf(Staff::class);
});

it('AC-4: exposes the documents of a document type', function () {
    dokumenCreate($this->f->s1, 'KK');
    dokumenCreate($this->f->s2, 'KK');
    dokumenCreate($this->f->s1, 'AKTA');

    expect($this->f->types['KK']->documents()->count())->toBe(2);
});

it('AC-5: masks numbers and derives display status', function () {
    $mask = fn (?string $number): ?string => (new Document)->forceFill(['number' => $number])->maskedNumber();

    expect($mask('3201234567890001'))->toBe('3201••••••••0001')
        ->and($mask('12345678'))->toBe('••••••••')
        ->and($mask('123456789'))->toBe('1234•6789')
        ->and($mask(null))->toBeNull();
});

it('AC-5: derives display status from the replacement', function () {
    $valid = (new Document)->forceFill(['status' => 'valid']);
    $replaced = (new Document)->forceFill(['status' => 'valid', 'replaced_by_id' => 5]);

    expect($valid->display_status)->toBe('valid')
        ->and($replaced->display_status)->toBe('diganti')
        ->and((new Document)->forceFill(['status' => 'ditolak'])->display_status)->toBe('ditolak')
        ->and((new Document)->forceFill(['status' => 'menunggu'])->display_status)->toBe('menunggu')
        ->and(Document::STATUS_COLORS)->toEqual(['menunggu' => 'warning', 'valid' => 'success', 'ditolak' => 'danger', 'diganti' => 'gray']);
});

it('AC-5: scopes current and expired documents', function () {
    $old = dokumenCreate($this->f->s1, 'RAPOR');
    $new = dokumenCreate($this->f->s1, 'RAPOR');
    $old->forceFill(['replaced_by_id' => $new->id])->save();
    $past = dokumenCreate($this->f->s1, 'BPJS', ['expires_on' => '2026-10-14']);
    $today = dokumenCreate($this->f->s1, 'BPJS', ['expires_on' => '2026-10-15']);
    $none = dokumenCreate($this->f->s1, 'BPJS', ['expires_on' => null]);

    expect(Document::query()->current()->pluck('id')->sort()->values()->all())->toBe(collect([$new->id, $past->id, $today->id, $none->id])->sort()->values()->all())
        ->and(Document::query()->expired()->pluck('id')->all())->toBe([$past->id])
        ->and(Document::query()->expired(CarbonImmutable::parse('2026-10-16'))->pluck('id')->sort()->values()->all())->toBe(collect([$past->id, $today->id])->sort()->values()->all());
});

it('AC-5: resolves the confidential flag from the current document type', function () {
    $document = dokumenCreate($this->f->s1, 'IJAZAH');

    expect($document->isConfidential())->toBeFalse();

    $this->f->types['IJAZAH']->update(['is_confidential' => true]);

    expect($document->fresh()->isConfidential())->toBeTrue();
});

/**
 * Dokumen satu per pemilik: santri SD, santri SLTP, santri SD yang diarsipkan, wali santri SD, wali santri SD dan SLTP,
 * wali tanpa santri, staff, dan satu baris `achievement` yang ditulis langsung.
 *
 * @return array<string, int>
 */
function dokumenScopeRows(object $f): array
{
    $archived = Student::factory()->forCohort($f->cohortSd)->create();
    $both = Guardian::factory()->create();
    $both->students()->attach([$f->s1->id => ['relation' => 'ibu', 'is_billing_contact' => false], $f->s2->id => ['relation' => 'ibu', 'is_billing_contact' => false]]);
    $alone = Guardian::factory()->create();
    $rows = [
        's1' => dokumenCreate($f->s1, 'KK')->id,
        's2' => dokumenCreate($f->s2, 'KK')->id,
        'archived' => dokumenCreate($archived, 'KK')->id,
        'g1' => dokumenCreate($f->g1, 'KK')->id,
        'both' => dokumenCreate($both, 'KK')->id,
        'alone' => dokumenCreate($alone, 'KK')->id,
        't1' => dokumenCreate($f->t1, 'KK')->id,
    ];
    $archived->delete();

    $rows['achievement'] = DB::table('documents')->insertGetId([
        'document_type_id' => $f->types['SERTIFIKAT']->id, 'documentable_type' => 'achievement', 'documentable_id' => 1,
        'file_path' => 'documents/achievement/1/x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'size_kb' => 1,
        'uploaded_by' => $f->admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $rows;
}

it('AC-28: scopes documents by unit', function (array $units, array $visible) {
    $rows = dokumenScopeRows($this->f);
    $user = keuanganUser('admin-sekolah', $units);
    $this->actingAs($user);

    expect(Document::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect($visible)->map(fn (string $key): int => $rows[$key])->sort()->values()->all());
})->with([
    'sltp' => [['SLTP'], ['s2', 'both', 't1']],
    'sd' => [['SD'], ['s1', 'archived', 'g1', 'both', 't1']],
    'sd and sltp' => [['SD', 'SLTP'], ['s1', 's2', 'archived', 'g1', 'both', 't1']],
    'slta' => [['SLTA'], ['t1']],
    'none' => [[], ['s1', 's2', 'archived', 'g1', 'both', 'alone', 't1', 'achievement']],
]);

it('AC-28: lets code without a user and the unscoped query see every document', function () {
    $rows = dokumenScopeRows($this->f);

    expect(Document::query()->count())->toBe(count($rows));

    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    expect(Document::withoutUnitScope()->count())->toBe(count($rows));
});

it('AC-28: hides documents of other units from relations and route binding lookups', function () {
    $rows = dokumenScopeRows($this->f);
    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    expect(Document::query()->find($rows['s1']))->toBeNull()
        ->and(Document::query()->find($rows['s2']))->not->toBeNull()
        ->and($this->f->g1->documents()->count())->toBe(0);
});
