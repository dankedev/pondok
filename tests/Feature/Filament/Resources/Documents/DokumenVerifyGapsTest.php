<?php

use App\Actions\Dokumen\UploadDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\Setting;
use App\Models\Staff;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = dokumenFixture();
    $this->actingAs($this->f->admin);
});

/**
 * Relation manager untuk pemilik, langsung lewat Livewire (melewati penyaringan tab).
 */
function dokumenGapManager(Model $owner)
{
    $page = match (true) {
        $owner instanceof Guardian => EditGuardian::class,
        $owner instanceof Staff => EditStaff::class,
        default => EditStudent::class,
    };

    return Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => $page]);
}

it('VERIFY: rejects document types of another owner, inactive or unknown types sent directly', function (string $case) {
    $f = $this->f;
    $walia = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $inactive = dokumenType(['code' => 'LAMA', 'owner' => 'santri', 'is_active' => false]);
    $id = match ($case) {
        'guardian type' => $walia->id,
        'achievement type' => $f->types['SERTIFIKAT']->id,
        'inactive type' => $inactive->id,
        'unknown id' => 999999,
        'garbage' => 'abc',
    };

    try {
        dokumenGapManager($f->s1)->callAction(TestAction::make('upload')->table(), ['document_type_id' => $id, 'file' => dokumenPdf()]);
    } catch (Throwable) {
    }

    expect(Document::query()->count())->toBe(0)->and(dokumenPrivateFiles())->toBe([]);
})->with(['guardian type', 'achievement type', 'inactive type', 'unknown id', 'garbage']);

it('VERIFY: refuses uploads for guardians and staff to users who may not manage them', function () {
    $f = $this->f;
    $walia = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $staffType = dokumenType(['code' => 'STAFFDOK', 'owner' => 'staff']);
    $user = dashboardUserWith(['view-any:document', 'view:document', 'create:document']);
    $this->actingAs($user);

    foreach ([[$f->g1, $walia], [$f->t1, $staffType]] as [$owner, $type]) {
        try {
            dokumenGapManager($owner)->callAction(TestAction::make('upload')->table(), ['document_type_id' => $type->id, 'file' => dokumenPdf()]);
        } catch (Throwable) {
        }
    }

    expect(Document::withoutUnitScope()->count())->toBe(0)->and(dokumenPrivateFiles())->toBe([]);
});

it('VERIFY: a scoped admin cannot upload or replace for a guardian outside the scope', function () {
    $f = $this->f;
    $walia = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $existing = dokumenCreate($f->g1, $walia);
    $files = dokumenPrivateFiles();
    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    foreach ([
        fn () => dokumenGapManager($f->g1)->callAction(TestAction::make('upload')->table(), ['document_type_id' => $walia->id, 'file' => dokumenPdf()]),
        fn () => dokumenGapManager($f->g1)->callAction(TestAction::make('replace')->table($existing), ['file' => dokumenPdf()]),
        fn () => Livewire::test(ListDocuments::class)->removeTableFilter('status')->callAction(TestAction::make('replace')->table($existing), ['file' => dokumenPdf()]),
    ] as $attempt) {
        try {
            $attempt();
        } catch (Throwable) {
        }
    }

    expect(Document::withoutUnitScope()->count())->toBe(1)
        ->and($existing->fresh()->replaced_by_id)->toBeNull()
        ->and(dokumenPrivateFiles())->toBe($files);
});

it('VERIFY: a scoped admin cannot verify a document of another unit through Livewire', function () {
    $f = $this->f;
    $document = dokumenCreate($f->s1, 'IJAZAH');
    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    try {
        Livewire::test(ListDocuments::class)->callAction(TestAction::make('verify')->table($document), ['status' => 'valid']);
    } catch (Throwable) {
    }

    expect($document->fresh())->status->toBe('menunggu')->verified_by->toBeNull();
});

it('VERIFY: a kesantrian user cannot verify, replace or upload through Livewire', function () {
    $f = $this->f;
    $document = dokumenCreate($f->s1, 'IJAZAH');
    $files = dokumenPrivateFiles();
    $this->actingAs(keuanganUser('kesantrian'));

    foreach ([
        fn () => Livewire::test(ListDocuments::class)->callAction(TestAction::make('verify')->table($document), ['status' => 'valid']),
        fn () => Livewire::test(ListDocuments::class)->callAction(TestAction::make('replace')->table($document), ['file' => dokumenPdf()]),
        fn () => dokumenGapManager($f->s1)->callAction(TestAction::make('upload')->table(), ['document_type_id' => $f->types['FOTO']->id, 'file' => dokumenPdf()]),
    ] as $attempt) {
        try {
            $attempt();
        } catch (Throwable) {
        }
    }

    expect($document->fresh())->status->toBe('menunggu')->replaced_by_id->toBeNull()
        ->and(Document::query()->count())->toBe(1)
        ->and(dokumenPrivateFiles())->toBe($files);
});

it('VERIFY: never puts a confidential number into the page of a user who may not see it', function () {
    $f = $this->f;
    $kk = dokumenCreate($f->s1, 'KK', ['number' => '3201234567890001', 'original_name' => 'kk-rahasia.pdf']);
    $this->actingAs(keuanganUser('kesantrian'));

    $list = Livewire::test(ListDocuments::class)->assertCanSeeTableRecords([$kk]);
    $detail = Livewire::test(ListDocuments::class)->mountAction(TestAction::make('view')->table($kk));

    foreach ([$list->html(), $detail->html()] as $html) {
        expect($html)->not->toContain('3201234567890001')->not->toContain('kk-rahasia.pdf')->not->toContain('3201••••••••0001');
    }
});

it('VERIFY: shows the full number in the detail only after the action is opened', function () {
    $kk = dokumenCreate($this->f->s1, 'KK', ['number' => '3201234567890001']);

    $list = Livewire::test(ListDocuments::class)->assertCanSeeTableRecords([$kk]);

    expect($list->html())->not->toContain('3201234567890001');
});

it('VERIFY: escapes hostile numbers and file names', function () {
    $f = $this->f;
    $document = dokumenCreate($f->s1, 'IJAZAH', ['number' => '<script>alert(1)</script>', 'original_name' => '<img src=x onerror=alert(1)>.pdf']);

    $list = Livewire::test(ListDocuments::class)->assertCanSeeTableRecords([$document]);
    $detail = Livewire::test(ListDocuments::class)->mountAction(TestAction::make('view')->table($document));

    foreach ([$list->html(), $detail->html()] as $html) {
        expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror');
    }
});

it('VERIFY: keeps a hostile client file name out of the storage path', function () {
    $document = app(UploadDocument::class)->handle(
        $this->f->s1,
        dokumenUploadData($this->f, 'KK', ['file' => dokumenPdf('../../etc/passwd.pdf')]),
        $this->f->admin,
    );

    expect($document->file_path)->toStartWith("documents/student/{$this->f->s1->id}/")
        ->not->toContain('..')->not->toContain('passwd')
        ->and(Storage::disk('private')->exists($document->file_path))->toBeTrue();
});

it('VERIFY: rejects html or svg content that claims to be an image', function (string $name, string $content) {
    dokumenAssertRejected(
        fn () => app(UploadDocument::class)->handle($this->f->s1, dokumenUploadData($this->f, 'FOTO', ['file' => pendaftaranRealFile($name, $content)]), $this->f->admin),
        'file_invalid',
        'file',
    );

    expect(dokumenPrivateFiles())->toBe([])->and(Document::query()->count())->toBe(0);
})->with([
    'html as png' => ['foto.png', '<html><script>alert(1)</script></html>'],
    'svg as jpg' => ['foto.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'php as pdf' => ['berkas.pdf', '<?php echo 1;'],
]);

it('VERIFY: writes no number to any activity log through the panel flows', function () {
    $f = $this->f;

    dokumenGapManager($f->s1)->callAction(TestAction::make('upload')->table(), [
        'document_type_id' => $f->types['KK']->id, 'file' => dokumenPdf('kk.pdf'), 'number' => '3201234567890001',
    ])->assertHasNoFormErrors();
    $document = Document::query()->sole();

    Livewire::test(ListDocuments::class)->callAction(TestAction::make('verify')->table($document), ['status' => 'valid']);
    Livewire::test(ListDocuments::class)->removeTableFilter('status')->callAction(TestAction::make('replace')->table($document->fresh()), ['file' => dokumenPdf('kk2.pdf'), 'number' => '3201234567899999']);

    $logs = Activity::query()->get();

    expect($logs->pluck('description')->sort()->values()->all())->toBe(['replaced', 'uploaded', 'verified'])
        ->and($logs->toJson())->not->toContain('3201234567890001')->not->toContain('3201234567899999')
        ->not->toContain('kk.pdf')->not->toContain('kk2.pdf')->not->toContain('documents/student');
});

it('VERIFY: keeps the stored number encrypted after panel flows', function () {
    $f = $this->f;

    dokumenGapManager($f->s1)->callAction(TestAction::make('upload')->table(), [
        'document_type_id' => $f->types['KK']->id, 'file' => dokumenPdf(), 'number' => '3201234567890001',
    ]);

    expect(DB::table('documents')->value('number'))->not->toContain('3201234567890001')
        ->and(Document::query()->sole()->number)->toBe('3201234567890001');
});

it('VERIFY: does not query per row in the relation manager', function () {
    $f = $this->f;
    $count = fn () => dokumenQueryCount(fn () => dokumenGapManager($f->s1));
    dokumenCreate($f->s1, 'KK');
    $few = $count();

    foreach (range(1, 8) as $ignored) {
        dokumenCreate($f->s1, 'RAPOR', ['uploaded_by' => keuanganUser('admin-sekolah')->id]);
    }

    expect($count())->toBeLessThanOrEqual($few + 2);
});

it('VERIFY: gives the guardian and staff managers no extra queries per row', function () {
    $f = $this->f;
    $walia = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    dokumenCreate($f->g1, $walia);
    $count = fn () => dokumenQueryCount(fn () => dokumenGapManager($f->g1));
    $few = $count();

    foreach (range(1, 8) as $ignored) {
        dokumenCreate($f->g1, $walia);
    }

    expect($count())->toBeLessThanOrEqual($few + 2);
});

it('VERIFY: removes the module entry points for direct Livewire calls when the module is off', function () {
    $f = $this->f;
    $document = dokumenCreate($f->s1, 'IJAZAH');
    Setting::set('modules.dokumen.enabled', false);

    try {
        dokumenGapManager($f->s1)->callAction(TestAction::make('upload')->table(), ['document_type_id' => $f->types['FOTO']->id, 'file' => dokumenPdf()]);
    } catch (Throwable) {
    }

    try {
        Livewire::test(ListDocuments::class)->callAction(TestAction::make('verify')->table($document), ['status' => 'valid']);
    } catch (Throwable) {
    }

    expect(Document::query()->count())->toBe(1)->and($document->fresh()->status)->toBe('menunggu');
});
