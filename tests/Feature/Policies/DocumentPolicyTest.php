<?php

use App\Models\Document;
use App\Models\User;
use App\Policies\DocumentPolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->f = dokumenFixture();
    $this->kk = dokumenCreate($this->f->s1, 'KK');
    $this->ijazah = dokumenCreate($this->f->s1, 'IJAZAH');
});

/**
 * Hasil `can()` untuk satu ability pada dokumen.
 */
function dokumenCan(User $user, string $ability, Document $document): bool
{
    return $user->can($ability, $document);
}

it('AC-29: guards confidential documents by permission', function (string $role, array $expected) {
    $user = keuanganUser($role);

    $actual = [
        'viewAny' => $user->can('viewAny', Document::class),
        'view kk' => dokumenCan($user, 'view', $this->kk),
        'view ijazah' => dokumenCan($user, 'view', $this->ijazah),
        'download kk' => dokumenCan($user, 'download', $this->kk),
        'download ijazah' => dokumenCan($user, 'download', $this->ijazah),
        'verify kk' => dokumenCan($user, 'verify', $this->kk),
        'verify ijazah' => dokumenCan($user, 'verify', $this->ijazah),
        'create' => $user->can('create', Document::class),
    ];

    expect($actual)->toBe($expected);
})->with([
    'admin-sekolah' => ['admin-sekolah', ['viewAny' => true, 'view kk' => true, 'view ijazah' => true, 'download kk' => true, 'download ijazah' => true, 'verify kk' => true, 'verify ijazah' => true, 'create' => true]],
    'kesantrian' => ['kesantrian', ['viewAny' => true, 'view kk' => true, 'view ijazah' => true, 'download kk' => false, 'download ijazah' => true, 'verify kk' => false, 'verify ijazah' => false, 'create' => false]],
    'bendahara' => ['bendahara', ['viewAny' => false, 'view kk' => false, 'view ijazah' => false, 'download kk' => false, 'download ijazah' => false, 'verify kk' => false, 'verify ijazah' => false, 'create' => false]],
    'admin-psb' => ['admin-psb', ['viewAny' => false, 'view kk' => false, 'view ijazah' => false, 'download kk' => false, 'download ijazah' => false, 'verify kk' => false, 'verify ijazah' => false, 'create' => false]],
    'musyrif' => ['musyrif', ['viewAny' => false, 'view kk' => false, 'view ijazah' => false, 'download kk' => false, 'download ijazah' => false, 'verify kk' => false, 'verify ijazah' => false, 'create' => false]],
]);

it('AC-29: lets super-admin do everything', function () {
    $user = keuanganUser('super-admin');

    expect(dokumenCan($user, 'download', $this->kk))->toBeTrue()
        ->and(dokumenCan($user, 'verify', $this->kk))->toBeTrue()
        ->and(dokumenCan($user, 'view', $this->ijazah))->toBeTrue();
});

it('AC-29: follows the confidential flag of the document type at the time of the check', function () {
    $kesantrian = keuanganUser('kesantrian');

    expect(dokumenCan($kesantrian, 'download', $this->ijazah))->toBeTrue();

    $this->f->types['IJAZAH']->update(['is_confidential' => true]);

    expect(dokumenCan($kesantrian, 'download', $this->ijazah->fresh()))->toBeFalse()
        ->and(dokumenCan($kesantrian, 'view', $this->ijazah->fresh()))->toBeTrue()
        ->and(dokumenCan(keuanganUser('admin-sekolah'), 'download', $this->ijazah->fresh()))->toBeTrue();

    $this->f->types['IJAZAH']->update(['is_confidential' => false]);

    expect(dokumenCan($kesantrian, 'download', $this->ijazah->fresh()))->toBeTrue();
});

it('AC-29: does not let anyone verify a replaced document', function () {
    $new = dokumenCreate($this->f->s1, 'IJAZAH');
    $this->ijazah->forceFill(['replaced_by_id' => $new->id])->save();
    $admin = keuanganUser('admin-sekolah');

    expect(dokumenCan($admin, 'verify', $this->ijazah->fresh()))->toBeFalse()
        ->and(dokumenCan($admin, 'verify', $new->fresh()))->toBeTrue()
        ->and((new DocumentPolicy)->verify($admin, $this->ijazah->fresh()))->toBeFalse();
});

it('AC-29: limits owner types per role', function () {
    $f = $this->f;
    $guardianType = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $staffType = dokumenType(['code' => 'STAFFDOK', 'owner' => 'staff']);
    $guardianDocument = dokumenCreate($f->g1, $guardianType);
    $staffDocument = dokumenCreate($f->t1, $staffType);
    $kesantrian = keuanganUser('kesantrian');
    $admin = keuanganUser('admin-sekolah');

    foreach (['view', 'download'] as $ability) {
        expect(dokumenCan($kesantrian, $ability, $guardianDocument))->toBeFalse("kesantrian {$ability} guardian")
            ->and(dokumenCan($kesantrian, $ability, $staffDocument))->toBeFalse("kesantrian {$ability} staff")
            ->and(dokumenCan($admin, $ability, $guardianDocument))->toBeTrue("admin {$ability} guardian")
            ->and(dokumenCan($admin, $ability, $staffDocument))->toBeTrue("admin {$ability} staff");
    }

    expect(dokumenCan($admin, 'verify', $guardianDocument))->toBeTrue()
        ->and(dokumenCan($kesantrian, 'verify', $guardianDocument))->toBeFalse()
        ->and(Document::visibleOwnerTypesFor($kesantrian))->toEqualCanonicalizing(['student'])
        ->and(Document::visibleOwnerTypesFor($admin))->toEqualCanonicalizing(['student', 'guardian', 'staff'])
        ->and(Document::visibleOwnerTypesFor(keuanganUser('super-admin')))->toContain('student', 'guardian', 'staff');
});

it('AC-29: shows guardian and staff documents to users who manage guardians and staff', function () {
    $guardianType = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $document = dokumenCreate($this->f->g1, $guardianType);
    $viewerOnly = dashboardUserWith(['view-any:document', 'view:document', 'view-any:guardian', 'view:guardian']);
    $manager = dashboardUserWith(['view-any:document', 'view:document', 'update:guardian']);

    expect(dokumenCan($viewerOnly, 'view', $document))->toBeFalse()
        ->and(Document::visibleOwnerTypesFor($viewerOnly))->toEqualCanonicalizing(['student'])
        ->and(dokumenCan($manager, 'view', $document))->toBeTrue()
        ->and(Document::visibleOwnerTypesFor($manager))->toEqualCanonicalizing(['student', 'guardian']);
});

it('AC-29: keeps documents of unknown owner types for super-admin only', function () {
    $document = (new Document)->forceFill(['documentable_type' => 'achievement', 'documentable_id' => 1, 'document_type_id' => $this->f->types['SERTIFIKAT']->id]);
    $admin = keuanganUser('admin-sekolah');

    expect(dokumenCan($admin, 'view', $document))->toBeFalse()
        ->and(dokumenCan(keuanganUser('super-admin'), 'view', $document))->toBeTrue()
        ->and(Document::visibleOwnerTypesFor($admin))->not->toContain('achievement');
});

it('AC-29: lets upload types follow the confidential permission', function () {
    $creator = dashboardUserWith(['view-any:document', 'create:document']);
    $admin = keuanganUser('admin-sekolah');
    $kk = $this->f->types['KK'];
    $ijazah = $this->f->types['IJAZAH'];

    expect(Gate::forUser($admin)->allows('uploadType', [Document::class, $kk]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('uploadType', [Document::class, $ijazah]))->toBeTrue()
        ->and(Gate::forUser($creator)->allows('uploadType', [Document::class, $ijazah]))->toBeTrue()
        ->and(Gate::forUser($creator)->allows('uploadType', [Document::class, $kk]))->toBeFalse();
});

it('AC-29: checks the permission that each policy method relies on', function () {
    expect(Permission::query()->whereIn('name', ['verify:document', 'view-confidential:document'])->count())->toBe(2);

    $onlyView = dashboardUserWith(['view-any:document', 'view:document']);
    $verifier = dashboardUserWith(['view-any:document', 'view:document', 'verify:document']);
    $confidential = dashboardUserWith(['view-any:document', 'view:document', 'verify:document', 'view-confidential:document']);

    expect(dokumenCan($onlyView, 'verify', $this->ijazah))->toBeFalse()
        ->and(dokumenCan($verifier, 'verify', $this->ijazah))->toBeTrue()
        ->and(dokumenCan($verifier, 'verify', $this->kk))->toBeFalse()
        ->and(dokumenCan($verifier, 'download', $this->kk))->toBeFalse()
        ->and(dokumenCan($confidential, 'verify', $this->kk))->toBeTrue()
        ->and(dokumenCan($confidential, 'download', $this->kk))->toBeTrue()
        ->and($onlyView->can('viewConfidential', Document::class))->toBeFalse()
        ->and($confidential->can('viewConfidential', Document::class))->toBeTrue();
});
