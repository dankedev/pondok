<?php

use App\Actions\Dokumen\UpdateLetterSigners;
use App\Filament\Resources\IssuedLetters\Pages\ListIssuedLetters;
use App\Models\IssuedLetter;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
| F063 VERIFY: celah tambahan di luar LetterSignersTest (duplikat hasil resolusi, escape XSS,
| surat lama di detail, modal pengaturan, akses lintas peran/jenjang).
*/

beforeEach(function () {
    $this->f = suratFixture();
    $this->homeroom = suratSigner(['name' => 'Ust. Wali Kelas', 'position' => 'Wali Kelas 4A']);
    $this->f->classSd->forceFill(['homeroom_staff_id' => $this->homeroom->id])->save();
});

function vsSlots(object $f, string $type = 'keterangan_aktif'): void
{
    Setting::set('letters.signers', [$type => [
        ['label' => 'Wali Kelas', 'source' => 'homeroom'],
        ['label' => 'Mengetahui', 'source' => 'staff', 'staff_id' => $f->t1->id],
    ]]);
}

it('rejects a resolved homeroom that equals a staff slot without spending a number', function () {
    $f = $this->f;
    $f->classSd->forceFill(['homeroom_staff_id' => $f->t1->id])->save();
    vsSlots($f);

    suratAssertRejected(fn () => suratIssue($f, ['signers' => null]), 'signer_duplicate', 'signers.1.staff_id');

    expect(IssuedLetter::withoutGlobalScopes()->count())->toBe(0)->and(dokumenActivities())->toHaveCount(0);
});

it('escapes signer label and name on print, verification and card', function () {
    $f = $this->f;
    $evil = suratSigner(['name' => '<b>Ust. Jahat</b>', 'position' => '<i>Mudir</i>']);
    Setting::set('letters.signers', [
        'keterangan_aktif' => [['label' => '<script>alert(1)</script>', 'source' => 'staff', 'staff_id' => $evil->id]],
        'kartu_santri' => [['label' => '<script>alert(2)</script>', 'source' => 'staff', 'staff_id' => $evil->id]],
    ]);
    $letter = suratIssue($f, ['signers' => null]);
    $card = suratIssue($f, ['type' => 'kartu_santri', 'signers' => null]);

    $pages = [
        test()->actingAs($f->admin)->get("/admin/letters/{$letter->id}/print")->assertOk()->getContent(),
        test()->get('/verifikasi-surat/'.$letter->verification_code)->assertOk()->getContent(),
        test()->actingAs($f->admin)->get("/admin/letters/{$card->id}/print")->assertOk()->getContent(),
    ];

    foreach ($pages as $i => $html) {
        expect($html)->not->toContain('<script>alert')->not->toContain('<b>Ust. Jahat', "halaman {$i}")->not->toContain('<i>Mudir');
    }
});

it('opens the detail modal of an old single-signer letter and of a multi-signer letter', function () {
    $f = $this->f;
    vsSlots($f);
    $new = suratIssue($f, ['signers' => null]);
    $old = suratIssue($f, ['signers' => null]);
    $snapshot = IssuedLetter::withoutGlobalScopes()->find($old->id)->snapshot;
    unset($snapshot['signers']);
    $snapshot['signer'] = ['name' => 'Ust. Lama', 'position' => 'Mudir Lama', 'nip' => null];
    DB::table('issued_letters')->where('id', $old->id)->update(['snapshot' => json_encode($snapshot)]);

    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->mountTableAction('view', $old)
        ->assertSee('Ust. Lama')
        ->unmountTableAction()
        ->mountTableAction('view', $new)
        ->assertSee('Ust. Wali Kelas')->assertSee('Ust. Hasan');
});

it('keeps the setting unchanged and shows an error when the modal saves a duplicate staff', function () {
    $f = $this->f;
    vsSlots($f);
    $before = Setting::get('letters.signers');
    $slot = ['label' => 'Kepala', 'source' => 'staff', 'staff_id' => $f->t1->id];
    $data = ['signers' => ['keterangan_aktif' => [$slot, [...$slot, 'label' => 'Lagi']]]];
    foreach (['pindah', 'rekomendasi', 'syahadah', 'kartu_santri'] as $type) {
        $data['signers'][$type] = [['label' => 'Penandatangan', 'source' => 'homeroom']];
    }
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)->callAction('configureSigners', $data);

    expect(Setting::get('letters.signers'))->toBe($before)
        ->and(dokumenActivities()->where('event', 'signers_updated'))->toHaveCount(0);
});

it('does not let a role without numbering permission change signers by forcing the action', function () {
    $role = Role::create(['name' => 'penerbit-surat', 'guard_name' => 'web']);
    $role->givePermissionTo(['view-any:issued-letter', 'view:issued-letter', 'create:issued-letter']);
    $user = suratUser('musyrif');
    $user->syncRoles([$role]);
    $this->actingAs($user);

    try {
        Livewire::test(ListIssuedLetters::class)
            ->callAction('configureSigners', ['signers' => ['keterangan_aktif' => [['label' => 'X', 'source' => 'homeroom']]]]);
    } catch (Throwable) {
        // aksi tersembunyi boleh melempar; yang dinilai adalah efeknya.
    }

    expect(Setting::get('letters.signers'))->toBeNull()
        ->and(dokumenActivities()->where('event', 'signers_updated'))->toHaveCount(0);
});

it('fills the issue form with the slot count of the chosen type and refills when the type changes', function () {
    $f = $this->f;
    vsSlots($f);
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->mountAction('issue')
        ->fillForm(['student_id' => $f->s1->id, 'type' => 'keterangan_aktif'])
        ->assertActionDataSet(function (array $state): array {
            expect($state['signers'])->toHaveCount(2);

            return [];
        })
        ->fillForm(['type' => 'pindah'])
        ->assertActionDataSet(function (array $state): array {
            expect($state['signers'])->toHaveCount(1)->and(array_values($state['signers'])[0]['staff_id'])->toBeNull();

            return [];
        });
});

it('does not let a unit-scoped user issue with a student of another unit through the signers form', function () {
    $f = $this->f;
    vsSlots($f);
    $this->actingAs($f->scoped);

    Livewire::test(ListIssuedLetters::class)
        ->mountAction('issue')
        ->fillForm(['student_id' => $f->s1->id, 'type' => 'keterangan_aktif', 'purpose' => 'Beasiswa'])
        ->fillForm(['signers' => [['staff_id' => $f->t1->id], ['staff_id' => $f->t1->id]]])
        ->callMountedAction();

    expect(IssuedLetter::withoutGlobalScopes()->count())->toBe(0);
});

it('trims the label when saving and stores the first change with old null', function () {
    $f = $this->f;

    app(UpdateLetterSigners::class)->handle(
        ['pindah' => [['label' => '  Kepala  ', 'source' => 'staff', 'staff_id' => $f->t1->id]]],
        $f->admin,
    );

    $log = dokumenActivities()->first();

    expect(Setting::get('letters.signers')['pindah'][0]['label'])->toBe('Kepala')
        ->and($log->properties['old'])->toBeNull()
        ->and(json_encode($log->properties))->not->toContain('Ust. Hasan');
});
