<?php

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Actions\Dokumen\ResolveLetterSigners;
use App\Actions\Dokumen\UpdateLetterSigners;
use App\Filament\Resources\IssuedLetters\Pages\ListIssuedLetters;
use App\Models\IssuedLetter;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
| F063: tanda tangan surat 1-3 orang per jenis. Diasumsikan: setting `letters.signers` (JSON per jenis, slot
| {label, source: staff|homeroom, staff_id?}), `IssuedLetter::signerList()`, header action `configureSigners`
| dengan Repeater `signers.<jenis>` (label, source, staff_id), dan Repeater `signers` (staff_id) di aksi `issue`.
*/

beforeEach(function () {
    $this->f = suratFixture();
    $this->homeroom = suratSigner(['name' => 'Ust. Wali Kelas', 'position' => 'Wali Kelas 4A', 'nip' => null]);
    $this->f->classSd->forceFill(['homeroom_staff_id' => $this->homeroom->id])->save();
});

/**
 * Slot dua tanda tangan: wali kelas dan staff `t1`.
 *
 * @return list<array<string, mixed>>
 */
function signerSlots(object $f): array
{
    return [
        ['label' => 'Wali Kelas', 'source' => 'homeroom'],
        ['label' => 'Mengetahui, Kepala Sekolah', 'source' => 'staff', 'staff_id' => $f->t1->id],
    ];
}

/**
 * Isi `letters.signers` langsung (tanpa Action).
 *
 * @param  list<array<string, mixed>>  $slots
 */
function signerConfigure(array $slots, string $type = 'keterangan_aktif'): void
{
    Setting::set('letters.signers', [$type => $slots]);
}

function signerPrint(object $f, int $id): string
{
    return test()->actingAs($f->admin)->get("/admin/letters/{$id}/print")->assertOk()->getContent();
}

function signerVerify(string $code): string
{
    return test()->get('/verifikasi-surat/'.$code)->assertOk()->getContent();
}

function signerCount(): int
{
    return IssuedLetter::withoutGlobalScopes()->count();
}

/**
 * Pesan ValidationException dari callback, atau null bila tidak melempar.
 *
 * @return array<string, list<string>>|null
 */
function signerErrors(Closure $callback): ?array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return null;
}

it('AC-1: saves one to three slots per type and logs exactly one signers_updated', function () {
    $f = $this->f;
    $data = [
        'keterangan_aktif' => signerSlots($f),
        'pindah' => [['label' => 'Kepala Sekolah', 'source' => 'staff', 'staff_id' => $f->t1->id]],
        'syahadah' => [
            ['label' => 'Wali Kelas', 'source' => 'homeroom'],
            ['label' => 'Kepala', 'source' => 'staff', 'staff_id' => $f->t1->id],
            ['label' => 'Mudir', 'source' => 'staff', 'staff_id' => $this->homeroom->id],
        ],
    ];

    app(UpdateLetterSigners::class)->handle($data, $f->admin);

    $stored = Setting::get('letters.signers');
    $activities = dokumenActivities();

    expect($stored['keterangan_aktif'])->toHaveCount(2)
        ->and($stored['syahadah'])->toHaveCount(3)
        ->and($stored['syahadah'][0]['source'])->toBe('homeroom')
        ->and($stored['pindah'][0]['staff_id'])->toBe($f->t1->id)
        ->and($activities)->toHaveCount(1)
        ->and($activities->first()->event)->toBe('signers_updated')
        ->and($activities->first()->causer_id)->toBe($f->admin->id)
        ->and($activities->first()->properties->keys()->all())->toContain('old', 'new');

    app(UpdateLetterSigners::class)->handle($data, $f->admin);

    expect(dokumenActivities())->toHaveCount(1);
});

it('AC-2: rejects invalid signer settings and changes nothing', function () {
    $f = $this->f;
    $slot = ['label' => 'Kepala', 'source' => 'staff', 'staff_id' => $f->t1->id];
    $cases = [
        'zero slots' => ['keterangan_aktif' => []],
        'four slots' => ['keterangan_aktif' => [
            ['label' => 'A', 'source' => 'homeroom'],
            ['label' => 'B', 'source' => 'staff', 'staff_id' => $f->t1->id],
            ['label' => 'C', 'source' => 'staff', 'staff_id' => $this->homeroom->id],
            ['label' => 'D', 'source' => 'homeroom'],
        ]],
        'empty label' => ['keterangan_aktif' => [[...$slot, 'label' => '   ']]],
        'label too long' => ['keterangan_aktif' => [[...$slot, 'label' => str_repeat('a', 61)]]],
        'unknown source' => ['keterangan_aktif' => [[...$slot, 'source' => 'kepala-asrama']]],
        'inactive staff' => ['keterangan_aktif' => [[...$slot, 'staff_id' => $f->t2->id]]],
        'missing staff' => ['keterangan_aktif' => [[...$slot, 'staff_id' => 999999]]],
        'staff source without staff' => ['keterangan_aktif' => [['label' => 'Kepala', 'source' => 'staff']]],
        'same staff twice' => ['keterangan_aktif' => [$slot, [...$slot, 'label' => 'Lagi']]],
        'unknown type' => ['surat_cinta' => [$slot]],
    ];
    app(UpdateLetterSigners::class)->handle(['pindah' => [$slot]], $f->admin);
    $before = Setting::get('letters.signers');
    $logs = dokumenActivities()->count();

    foreach ($cases as $name => $data) {
        $errors = signerErrors(fn () => app(UpdateLetterSigners::class)->handle($data, $f->admin));

        expect($errors)->not->toBeNull("{$name} harus ditolak")
            ->and(collect($errors)->keys()->every(fn (string $key): bool => str_starts_with($key, 'signers')))->toBeTrue("{$name}: kunci error harus berawalan signers");
        expect(Setting::get('letters.signers'))->toBe($before, "{$name}: setting berubah")
            ->and(dokumenActivities()->count())->toBe($logs, "{$name}: log bertambah");
    }

    $duplicate = signerErrors(fn () => app(UpdateLetterSigners::class)->handle($cases['same staff twice'], $f->admin));

    expect(collect($duplicate)->keys()->first())->toStartWith('signers.keterangan_aktif.');
});

it('AC-3: resolves staff and homeroom slots with fallbacks to null', function () {
    $f = $this->f;
    signerConfigure([...signerSlots($f), ['label' => 'Lain', 'source' => 'staff', 'staff_id' => $f->t2->id]]);
    $resolve = fn ($student = null): array => array_column(app(ResolveLetterSigners::class)->handle('keterangan_aktif', $student ?? $f->s1->fresh()), 'staff_id');

    expect($resolve())->toBe([$this->homeroom->id, $f->t1->id, null], 'staff nonaktif jadi null');

    $this->homeroom->forceFill(['is_active' => false])->save();
    expect($resolve())->toBe([null, $f->t1->id, null], 'wali kelas nonaktif');

    $this->homeroom->forceFill(['is_active' => true])->save();
    $f->classSd->forceFill(['homeroom_staff_id' => null])->save();
    expect($resolve())->toBe([null, $f->t1->id, null], 'rombel tanpa wali kelas');

    $f->classSd->forceFill(['homeroom_staff_id' => $this->homeroom->id])->save();
    $noClass = suratStudent($f, attributes: ['name' => 'Tanpa Rombel'], enrolled: false);
    expect($resolve($noClass))->toBe([null, $f->t1->id, null], 'santri tanpa rombel aktif');

    $f->t1->delete();
    expect($resolve())->toBe([$this->homeroom->id, null, null], 'staff hilang');
});

it('AC-3: gives one default manual slot for a type without settings', function () {
    $slots = app(ResolveLetterSigners::class)->handle('pindah', $this->f->s1->fresh());

    expect($slots)->toHaveCount(1)
        ->and($slots[0]['label'])->toBe('Penandatangan')
        ->and($slots[0]['staff_id'])->toBeNull();
});

it('AC-4: issues a letter with signers from the settings, in slot order', function () {
    $f = $this->f;
    signerConfigure(signerSlots($f));

    $letter = suratIssue($f, ['signers' => null]);
    $stored = IssuedLetter::withoutGlobalScopes()->findOrFail($letter->id);

    expect($stored->snapshot['signers'])->toEqual([
        ['label' => 'Wali Kelas', 'name' => 'Ust. Wali Kelas', 'position' => 'Wali Kelas 4A', 'nip' => null],
        ['label' => 'Mengetahui, Kepala Sekolah', 'name' => 'Ust. Hasan', 'position' => 'Kepala Sekolah', 'nip' => '1987'],
    ])->and($stored->signed_by_staff_id)->toBe($this->homeroom->id)
        ->and($stored->letter_no)->toBe('001/MAK/SKA/X/2026')
        ->and($stored->snapshot)->not->toHaveKey('signer');
});

it('AC-5: override replaces staff per slot while labels stay from the slot', function () {
    $f = $this->f;
    signerConfigure(signerSlots($f));
    $other = suratSigner(['name' => 'Ust. Pengganti', 'position' => 'Wakil']);

    $letter = suratIssue($f, ['signers' => [$f->t1->id, $other->id]]);
    $signers = IssuedLetter::withoutGlobalScopes()->findOrFail($letter->id)->snapshot['signers'];

    expect(array_column($signers, 'name'))->toBe(['Ust. Hasan', 'Ust. Pengganti'])
        ->and(array_column($signers, 'label'))->toBe(['Wali Kelas', 'Mengetahui, Kepala Sekolah'])
        ->and($letter->signed_by_staff_id)->toBe($f->t1->id);
});

it('AC-5: requires an override for an unresolved slot and spends no number', function () {
    $f = $this->f;
    signerConfigure(signerSlots($f));
    $f->classSd->forceFill(['homeroom_staff_id' => null])->save();

    suratAssertRejected(fn () => suratIssue($f, ['signers' => null]), 'signer_required', 'signers.0.staff_id');

    expect(signerCount())->toBe(0)->and(dokumenActivities())->toHaveCount(0);

    $letter = suratIssue($f, ['signers' => [$this->homeroom->id, null]]);

    expect($letter->signed_by_staff_id)->toBe($this->homeroom->id)
        ->and($letter->letter_no)->toBe('001/MAK/SKA/X/2026');
});

it('AC-5: rejects bad overrides without a letter or a used number', function () {
    $f = $this->f;
    signerConfigure(signerSlots($f));
    $cases = [
        'inactive staff' => [[$f->t2->id, $f->t1->id], 'signers.0.staff_id'],
        'same staff twice' => [[$f->t1->id, $f->t1->id], 'signers.1.staff_id'],
        'too few' => [[$f->t1->id], null],
        'too many' => [[$f->t1->id, $this->homeroom->id, $f->t1->id], null],
    ];

    foreach ($cases as $name => [$signers, $key]) {
        $errors = signerErrors(fn () => suratIssue($f, ['signers' => $signers]));

        expect($errors)->not->toBeNull("{$name} harus ditolak");

        if ($key !== null) {
            expect($errors)->toHaveKey($key);
        }
    }

    expect(signerCount())->toBe(0)->and(dokumenActivities())->toHaveCount(0)
        ->and(suratIssue($f, ['signers' => null])->letter_no)->toBe('001/MAK/SKA/X/2026');
});

it('AC-6: freezes signers after settings, staff and homeroom change', function () {
    $f = $this->f;
    signerConfigure(signerSlots($f));
    $letter = suratIssue($f, ['signers' => null]);
    $snapshot = IssuedLetter::withoutGlobalScopes()->find($letter->id)->snapshot;

    signerConfigure([['label' => 'Baru', 'source' => 'staff', 'staff_id' => $f->t1->id]]);
    $this->homeroom->forceFill(['name' => 'Ust. Ganti Nama', 'position' => 'Jabatan Baru'])->save();
    $newHomeroom = suratSigner(['name' => 'Ust. Wali Baru']);
    $f->classSd->forceFill(['homeroom_staff_id' => $newHomeroom->id])->save();

    $text = suratText(signerPrint($f, $letter->id));

    expect(IssuedLetter::withoutGlobalScopes()->find($letter->id)->snapshot)->toEqual($snapshot)
        ->and($text)->toContain('Ust. Wali Kelas')->toContain('Wali Kelas 4A')->not->toContain('Ust. Ganti Nama')->not->toContain('Ust. Wali Baru');

    signerConfigure(signerSlots($f));
    $fresh = suratIssue($f, ['signers' => null]);

    expect(IssuedLetter::withoutGlobalScopes()->find($fresh->id)->snapshot['signers'][0]['name'])->toBe('Ust. Wali Baru');
});

it('AC-7: shows an old letter with a single signer everywhere without error', function () {
    $f = $this->f;
    $old = suratIssue($f);
    $card = suratIssue($f, ['type' => 'kartu_santri']);

    foreach ([$old, $card] as $letter) {
        $snapshot = IssuedLetter::withoutGlobalScopes()->find($letter->id)->snapshot;
        unset($snapshot['signers']);
        $snapshot['signer'] = ['name' => 'Ust. Lama', 'position' => 'Mudir Lama', 'nip' => null];
        DB::table('issued_letters')->where('id', $letter->id)->update(['snapshot' => json_encode($snapshot)]);
    }

    $list = IssuedLetter::withoutGlobalScopes()->find($old->id)->signerList();

    expect($list)->toHaveCount(1)->and($list[0]['name'])->toBe('Ust. Lama')->and($list[0]['label'] ?? null)->toBeNull()
        ->and(suratText(signerPrint($f, $old->id)))->toContain('Ust. Lama')->toContain('Mudir Lama')
        ->and(suratText(signerPrint($f, $card->id)))->toContain('Ust. Lama')
        ->and(suratText(signerVerify($old->verification_code)))->toContain('Ust. Lama');

    $this->actingAs($f->admin);
    Livewire::test(ListIssuedLetters::class)->assertSuccessful()->assertCanSeeTableRecords([$old, $card]);
});

it('AC-7: tolerates a snapshot without any signer', function () {
    $f = $this->f;
    $letter = suratIssue($f);
    $snapshot = IssuedLetter::withoutGlobalScopes()->find($letter->id)->snapshot;
    unset($snapshot['signers']);
    DB::table('issued_letters')->where('id', $letter->id)->update(['snapshot' => json_encode($snapshot)]);

    expect(IssuedLetter::withoutGlobalScopes()->find($letter->id)->signerList())->toBe([]);

    signerPrint($f, $letter->id);
    signerVerify($letter->verification_code);
});

it('AC-8: prints, shows on the card and verifies all three signers in order', function () {
    $f = $this->f;
    $third = suratSigner(['name' => 'Ust. Ketiga', 'position' => 'Mudir']);
    signerConfigure([...signerSlots($f), ['label' => 'Menyetujui', 'source' => 'staff', 'staff_id' => $third->id]]);
    $letter = suratIssue($f, ['signers' => null]);
    $card = suratIssue($f, ['type' => 'kartu_santri', 'signers' => [$f->t1->id]]);
    signerConfigure([...signerSlots($f), ['label' => 'Menyetujui', 'source' => 'staff', 'staff_id' => $third->id]], 'kartu_santri');
    $card3 = suratIssue($f, ['type' => 'kartu_santri', 'signers' => null]);
    $expected = ['Wali Kelas', 'Ust. Wali Kelas', 'Mengetahui, Kepala Sekolah', 'Ust. Hasan', 'Menyetujui', 'Ust. Ketiga', 'Mudir'];

    $inOrder = function (string $text) use ($expected): void {
        $last = -1;

        foreach ($expected as $needle) {
            $pos = mb_strpos($text, $needle, $last + 1);
            expect($pos)->not->toBeFalse("{$needle} hilang atau salah urutan");
            $last = $pos;
        }
    };

    $inOrder(suratText(signerPrint($f, $letter->id)));
    $verify = suratText(signerVerify($letter->verification_code));
    $inOrder($verify);

    expect($verify)->not->toContain('Muhammad Fauzan Akbar')->not->toContain('2026000123')
        ->and(suratText(signerPrint($f, $card3->id)))->toContain('Ust. Wali Kelas')->toContain('Ust. Hasan')->toContain('Ust. Ketiga')
        ->and(suratText(signerPrint($f, $card->id)))->toContain('Ust. Hasan');
});

it('AC-9: logs signers on issue and keeps the cancel log, without the snapshot', function () {
    $f = $this->f;
    signerConfigure(signerSlots($f));
    $letter = suratIssue($f, ['signers' => null]);
    app(CancelIssuedLetter::class)->handle($letter, $f->admin, 'Salah ketik');

    $issued = dokumenActivities()->firstWhere('event', 'issued');
    $json = json_encode($issued->properties);

    expect($issued->getExtraProperty('letter_no'))->toBe('001/MAK/SKA/X/2026')
        ->and($issued->getExtraProperty('type'))->toBe('keterangan_aktif')
        ->and($json)->toContain('Ust. Wali Kelas')->toContain('Ust. Hasan')->toContain('Wali Kelas')
        ->not->toContain('Fauzan')->not->toContain('2026000123')->not->toContain('"snapshot"')
        ->and(dokumenActivities()->where('event', 'cancelled'))->toHaveCount(1);
});

it('AC-10: fills the issue form signers from settings and submits a changed choice', function () {
    $f = $this->f;
    signerConfigure(signerSlots($f));
    $other = suratSigner(['name' => 'Ust. Pilihan']);
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->mountAction('issue')
        ->fillForm(['student_id' => $f->s1->id, 'type' => 'keterangan_aktif', 'purpose' => 'Beasiswa'])
        ->assertActionDataSet(function (array $state): array {
            $ids = array_column(array_values($state['signers']), 'staff_id');
            expect($ids)->toHaveCount(2)->and($ids[0])->toBe($this->homeroom->id);

            return [];
        })
        ->fillForm(['signers' => [['staff_id' => $other->id], ['staff_id' => $f->t1->id]]])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $letter = IssuedLetter::query()->sole();

    expect($letter->signed_by_staff_id)->toBe($other->id)
        ->and(array_column($letter->snapshot['signers'], 'name'))->toBe(['Ust. Pilihan', 'Ust. Hasan']);
});

it('AC-10: leaves an unresolved homeroom slot empty and requires a choice', function () {
    $f = $this->f;
    signerConfigure(signerSlots($f));
    $f->classSd->forceFill(['homeroom_staff_id' => null])->save();
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->mountAction('issue')
        ->fillForm(['student_id' => $f->s1->id, 'type' => 'keterangan_aktif', 'purpose' => 'Beasiswa'])
        ->assertActionDataSet(function (array $state): array {
            expect(array_values($state['signers'])[0]['staff_id'])->toBeNull();

            return [];
        })
        ->callMountedAction();

    expect(IssuedLetter::query()->count())->toBe(0);
});

it('AC-11: lets a permitted role open, change and save the signers modal', function () {
    $f = $this->f;
    signerConfigure(signerSlots($f));
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('configureSigners')
        ->mountAction('configureSigners')
        ->assertActionDataSet(function (array $state): array {
            $slots = array_values($state['signers']['keterangan_aktif']);
            expect($slots)->toHaveCount(2)->and($slots[0]['source'])->toBe('homeroom')->and($slots[1]['staff_id'])->toBe($this->f->t1->id);

            return [];
        });

    $data = ['signers' => ['keterangan_aktif' => [['label' => 'Kepala', 'source' => 'staff', 'staff_id' => $f->t1->id]]]];
    foreach (['pindah', 'rekomendasi', 'syahadah', 'kartu_santri'] as $type) {
        $data['signers'][$type] = [['label' => 'Penandatangan', 'source' => 'homeroom']];
    }

    Livewire::test(ListIssuedLetters::class)->callAction('configureSigners', $data)->assertHasNoFormErrors();

    expect(Setting::get('letters.signers')['keterangan_aktif'])->toHaveCount(1)
        ->and(dokumenActivities()->where('event', 'signers_updated'))->toHaveCount(1);
});

it('AC-11: hides the signers action from a role without the numbering permission', function () {
    $role = Role::create(['name' => 'penerbit-surat', 'guard_name' => 'web']);
    $role->givePermissionTo(['view-any:issued-letter', 'view:issued-letter', 'create:issued-letter', 'cancel:issued-letter']);
    $user = suratUser('musyrif');
    $user->syncRoles([$role]);
    $this->actingAs($user);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionHidden('configureSigners');

    expect($user->can('configureNumbering', IssuedLetter::class))->toBeFalse()
        ->and(Setting::get('letters.signers'))->toBeNull();
});

it('AC-12: still hides students outside the unit scope', function () {
    $f = $this->f;

    suratAssertRejected(fn () => suratIssue($f, by: $f->scoped), 'student_not_found', 'student');
});

it('uses translated texts for the signer feature', function () {
    expectTranslatedKeys([
        'pondok.dokumen.errors.signer_required', 'pondok.dokumen.errors.signers_invalid',
        'pondok.letter_signers.title', 'pondok.letter_signers.saved',
    ]);
});
