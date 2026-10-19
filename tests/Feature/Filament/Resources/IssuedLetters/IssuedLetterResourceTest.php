<?php

use App\Actions\Dokumen\CancelIssuedLetter;
use App\Filament\Resources\IssuedLetters\IssuedLetterResource;
use App\Filament\Resources\IssuedLetters\Pages\ListIssuedLetters;
use App\Models\IssuedLetter;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->f = suratFixture();
});

/**
 * Data modal "Terbitkan surat" yang valid.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function suratIssueFormData(object $f, array $overrides = []): array
{
    return [
        'student_id' => $f->s1->id,
        'type' => 'keterangan_aktif',
        'signers' => [['staff_id' => $f->t1->id]],
        'purpose' => 'Persyaratan beasiswa',
        ...$overrides,
    ];
}

/**
 * Id yang ditawarkan sebuah Select: opsi statis ditambah hasil pencarian `$search` (bila Select mencari).
 *
 * @return list<int>
 */
function suratSelectIds(Select $select, string $search = ''): array
{
    $options = $select->getOptions();

    try {
        $options += $select->getSearchResults($search);
    } catch (Throwable) {
    }

    return array_map('intval', array_keys($options));
}

/**
 * Id yang ditawarkan Select `$field` pada modal aksi yang sedang terbuka.
 *
 * @return list<int>
 */
function suratModalOptions(Testable $component, string $field, string $search = ''): array
{
    $ids = [];

    $component->assertSchemaComponentExists($field, checkComponentUsing: function (Select $select) use (&$ids, $search): bool {
        $ids = suratSelectIds($select, $search);

        return true;
    });

    return $ids;
}

it('AC-34: issues a letter from the panel', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('issue')
        ->callAction('issue', suratIssueFormData($f))
        ->assertHasNoFormErrors();

    $letter = IssuedLetter::query()->sole();
    $notifications = suratNotifications();

    expect($letter->letter_no)->toBe('001/MAK/SKA/X/2026')
        ->and($letter->issued_by)->toBe($f->admin->id)
        ->and($letter->purpose)->toBe('Persyaratan beasiswa')
        ->and($notifications)->toContain('success|')->toContain('001/MAK/SKA/X/2026');
});

it('AC-34: offers the print link in the success notification', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)->assertActionVisible('issue')->callAction('issue', suratIssueFormData($f));

    $letter = IssuedLetter::query()->sole();
    $json = str_replace('\/', '/', json_encode(asramaNotifications()->all()));

    expect($json)->toContain("/admin/letters/{$letter->id}/print");
});

it('AC-34: requires a purpose', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('issue')
        ->callAction('issue', suratIssueFormData($f, ['purpose' => '']))
        ->assertHasFormErrors(['purpose']);

    expect(IssuedLetter::query()->count())->toBe(0);
});

it('AC-34: hides the purpose for a student card and issues it without one', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->mountAction('issue')
        ->assertFormFieldVisible('purpose')
        ->fillForm(['type' => 'kartu_santri'])
        ->assertFormFieldHidden('purpose')
        ->fillForm(['type' => 'pindah'])
        ->assertFormFieldVisible('purpose');

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('issue')
        ->callAction('issue', ['student_id' => $f->s1->id, 'type' => 'kartu_santri', 'signers' => [['staff_id' => $f->t1->id]]])
        ->assertHasNoFormErrors();

    $letter = IssuedLetter::query()->sole();

    expect($letter->letter_no)->toBe('001/MAK/KTS/X/2026')->and($letter->purpose)->toBeNull();
});

it('AC-34: refuses an inactive staff member as signer', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->callAction('issue', suratIssueFormData($f, ['signers' => [['staff_id' => $f->t2->id]]]));

    expect(IssuedLetter::query()->count())->toBe(0);
});

it('AC-34: offers students by name and hides archived ones', function () {
    $f = $this->f;
    $archived = suratStudent($f, attributes: ['name' => 'Budi Arsip']);
    $archived->delete();
    $this->actingAs($f->admin);

    $component = Livewire::test(ListIssuedLetters::class)->mountAction('issue');

    expect(suratModalOptions($component, 'student_id', 'Budi'))->toContain($f->s2->id)->not->toContain($archived->id)->not->toContain($f->s1->id)
        ->and(suratModalOptions($component, 'student_id', 'Fauzan'))->toContain($f->s1->id);
});

it('AC-34: labels students with their NIS', function () {
    $f = $this->f;
    $this->actingAs($f->admin);
    $label = null;

    Livewire::test(ListIssuedLetters::class)
        ->mountAction('issue')
        ->assertSchemaComponentExists('student_id', checkComponentUsing: function (Select $select) use (&$label, $f): bool {
            $label = ($select->getOptions() + $select->getSearchResults('Fauzan'))[$f->s1->id] ?? null;

            return true;
        });

    expect($label)->toContain('2026000123')->toContain('Muhammad Fauzan Akbar');
});

it('AC-34: lists letters newest first with the main columns and filters', function () {
    $f = $this->f;
    $first = suratIssue($f);
    $second = suratIssue($f, ['type' => 'pindah']);
    $third = suratIssue($f, student: $f->s2);
    app(CancelIssuedLetter::class)->handle($first, $f->admin, 'Salah');
    $this->actingAs($f->admin);

    $component = Livewire::test(ListIssuedLetters::class)
        ->assertCanSeeTableRecords([$third, $second, $first], inOrder: true)
        ->assertTableColumnExists('letter_no')
        ->assertTableColumnExists('type')
        ->assertTableColumnExists('issued_on')
        ->assertTableColumnExists('status');

    expect(tagihanPlainText($component))->toContain('001/MAK/SKA/X/2026')->toContain('Muhammad Fauzan Akbar')->toContain('Batal')->toContain('Terbit');

    $component->filterTable('type', 'pindah')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first, $third])
        ->removeTableFilter('type')
        ->filterTable('status', 'batal')->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second, $third])
        ->removeTableFilter('status')
        ->filterTable('unit', $f->sltp->id)->assertCanSeeTableRecords([$third])->assertCanNotSeeTableRecords([$first, $second]);
});

it('AC-34: filters by year', function () {
    $f = $this->f;
    $old = suratIssue($f);
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00', 'Asia/Jakarta'));
    $new = suratIssue($f);
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->filterTable('year', 2027)->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old])
        ->removeTableFilter('year')
        ->filterTable('year', 2026)->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$new]);
});

it('AC-34: searches by letter number, student name and NIS', function () {
    $f = $this->f;
    $own = suratIssue($f);
    $other = suratIssue($f, student: $f->s2);
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->searchTable('Budi Santoso')->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$own])
        ->searchTable('2026000123')->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other])
        ->searchTable($other->letter_no)->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$own]);
});

it('AC-34: shows the empty state with the issue action', function () {
    $this->actingAs($this->f->admin);

    $component = Livewire::test(ListIssuedLetters::class);

    expect(tagihanPlainText($component))->toContain('Belum ada surat keluar.');
});

it('AC-34: opens the detail with the verification code and url', function () {
    $f = $this->f;
    $letter = suratIssue($f);
    $this->actingAs($f->admin);

    $component = Livewire::test(ListIssuedLetters::class)
        ->mountAction(TestAction::make('view')->table($letter));

    expect(tagihanPlainText($component))->toContain($letter->letter_no)
        ->toContain($letter->formattedVerificationCode())
        ->toContain($letter->verificationUrl())
        ->toContain('Persyaratan beasiswa')
        ->toContain('Ust. Hasan');
});

it('AC-34: shows the cancellation in the detail of a cancelled letter', function () {
    $f = $this->f;
    $letter = suratIssue($f);
    app(CancelIssuedLetter::class)->handle($letter, $f->admin, 'Salah keperluan');
    $this->actingAs($f->admin);

    $component = Livewire::test(ListIssuedLetters::class)->mountAction(TestAction::make('view')->table($letter));

    expect(tagihanPlainText($component))->toContain('Salah keperluan');
});

it('AC-34: opens the print page in a new tab', function () {
    $f = $this->f;
    $letter = suratIssue($f);
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible(TestAction::make('print')->table($letter))
        ->assertActionHasUrl(TestAction::make('print')->table($letter), url("/admin/letters/{$letter->id}/print"))
        ->assertActionShouldOpenUrlInNewTab(TestAction::make('print')->table($letter));
});

it('AC-34: does not run a query per row', function () {
    $f = $this->f;
    $this->actingAs($f->admin);
    suratIssue($f);
    Livewire::test(ListIssuedLetters::class);

    $few = dokumenQueryCount(fn () => Livewire::test(ListIssuedLetters::class));

    foreach (range(1, 9) as $ignored) {
        suratIssue($f, student: suratStudent($f, $ignored % 2 === 0 ? 'SD' : 'SLTP'));
    }

    $this->actingAs($f->admin);
    $many = dokumenQueryCount(fn () => Livewire::test(ListIssuedLetters::class));

    expect($many)->toBeLessThanOrEqual($few + 2);
});

it('AC-35: scopes letters and students by unit', function () {
    $f = $this->f;
    $own = suratIssue($f, student: $f->s2);
    $other = suratIssue($f);
    $this->actingAs($f->scoped);

    $component = Livewire::test(ListIssuedLetters::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$other]);

    $mounted = Livewire::test(ListIssuedLetters::class)->mountAction('issue');

    expect(suratModalOptions($mounted, 'student_id', 'Fauzan'))->not->toContain($f->s1->id)
        ->and(suratModalOptions($mounted, 'student_id', 'Budi'))->toContain($f->s2->id)
        ->and(tagihanPlainText($component))->not->toContain('Muhammad Fauzan Akbar');
});

it('AC-35: limits the unit filter of a scoped user to its units', function () {
    $this->actingAs($this->f->scoped);

    Livewire::test(ListIssuedLetters::class)
        ->assertTableFilterExists('unit', fn ($filter): bool => array_keys($filter->getOptions()) === [$this->f->sltp->id]);
});

it('AC-35: rejects a student of another unit sent directly', function () {
    $f = $this->f;
    $this->actingAs($f->scoped);

    $component = Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('issue')
        ->callAction('issue', suratIssueFormData($f));

    expect(IssuedLetter::withoutGlobalScopes()->count())->toBe(0)
        ->and(dokumenSeeMessage($component, 'student_not_found'))->toBeTrue();
});

it('AC-35: lets a scoped user issue for a student in reach', function () {
    $f = $this->f;
    $this->actingAs($f->scoped);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('issue')
        ->callAction('issue', suratIssueFormData($f, ['student_id' => $f->s2->id]))
        ->assertHasNoFormErrors();

    expect(IssuedLetter::withoutGlobalScopes()->sole()->student_id)->toBe($f->s2->id);
});

it('AC-36: cancels letters from the table', function () {
    $f = $this->f;
    $letter = suratIssue($f);
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible(TestAction::make('cancel')->table($letter))
        ->callAction(TestAction::make('cancel')->table($letter), ['reason' => 'Salah keperluan'])
        ->assertHasNoFormErrors();

    $stored = IssuedLetter::query()->find($letter->id);

    expect($stored->status)->toBe('batal')
        ->and($stored->cancel_reason)->toBe('Salah keperluan')
        ->and($stored->cancelled_by)->toBe($f->admin->id)
        ->and(suratNotifications())->toContain('success|');
});

it('AC-36: hides the cancel action for a cancelled letter', function () {
    $f = $this->f;
    $open = suratIssue($f);
    $cancelled = suratIssue($f);
    app(CancelIssuedLetter::class)->handle($cancelled, $f->admin, 'Salah');
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible(TestAction::make('cancel')->table($open))
        ->assertActionHidden(TestAction::make('cancel')->table($cancelled));
});

it('AC-36: requires a reason to cancel', function () {
    $letter = suratIssue($this->f);
    $this->actingAs($this->f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible(TestAction::make('cancel')->table($letter))
        ->callAction(TestAction::make('cancel')->table($letter), ['reason' => ''])
        ->assertHasFormErrors(['reason']);

    expect($letter->fresh()->status)->toBe('terbit');
});

it('AC-36: hides cancel, issue and numbering actions from a user without those permissions', function () {
    $f = $this->f;
    $letter = suratIssue($f);
    $role = Role::create(['name' => 'pembaca-surat', 'guard_name' => 'web']);
    $role->givePermissionTo(['view-any:issued-letter', 'view:issued-letter']);
    $user = suratUser('musyrif');
    $user->syncRoles([$role]);
    $this->actingAs($user);

    Livewire::test(ListIssuedLetters::class)
        ->assertCanSeeTableRecords([$letter])
        ->assertActionHidden(TestAction::make('cancel')->table($letter))
        ->assertActionHidden('issue')
        ->assertActionHidden('configureNumbering');
});

it('AC-36: has no edit, delete or bulk actions', function () {
    $letter = suratIssue($this->f);
    $this->actingAs($this->f->admin);

    $component = Livewire::test(ListIssuedLetters::class)
        ->assertActionDoesNotExist(TestAction::make('edit')->table($letter))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($letter));
    $table = $component->instance()->getTable();

    expect($table->getFlatBulkActions())->toBe([])
        ->and(array_keys($table->getFlatActions()))->not->toContain('edit', 'delete')
        ->and(IssuedLetterResource::canEdit($letter))->toBeFalse()
        ->and(IssuedLetterResource::canDelete($letter))->toBeFalse();
});

it('AC-37: configures letter numbering', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('configureNumbering')
        ->mountAction('configureNumbering')
        ->assertActionDataSet(function (array $state): array {
            expect($state['number_format'])->toBe('{seq}/{school_code}/{type_code}/{month_roman}/{year}')
                ->and((int) $state['sequence_padding'])->toBe(3)
                ->and($state['type_codes']['keterangan_aktif'])->toBe('SKA')
                ->and($state['type_codes']['kartu_santri'])->toBe('KTS');

            return [];
        });

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('configureNumbering')
        ->callAction('configureNumbering', suratNumberingData(['number_format' => '{type_code}-{seq}/{year}', 'school_code' => 'ALK', 'sequence_padding' => 4]))
        ->assertHasNoFormErrors();

    expect(Setting::get('letters.number_format'))->toBe('{type_code}-{seq}/{year}')
        ->and(Setting::get('letters.school_code'))->toBe('ALK')
        ->and((int) Setting::get('letters.sequence_padding'))->toBe(4)
        ->and(suratNotifications())->toContain('success|');
});

it('AC-37: fills the form with the stored values', function () {
    Setting::set('letters.number_format', '{type_code}-{seq}/{year}');
    Setting::set('letters.school_code', 'ALK');
    Setting::set('letters.type_codes', ['pindah' => 'SPD']);
    $this->actingAs($this->f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->mountAction('configureNumbering')
        ->assertActionDataSet(function (array $state): array {
            expect($state['number_format'])->toBe('{type_code}-{seq}/{year}')
                ->and($state['school_code'])->toBe('ALK')
                ->and($state['type_codes']['pindah'])->toBe('SPD')
                ->and($state['type_codes']['keterangan_aktif'])->toBe('SKA');

            return [];
        });
});

it('AC-37: shows invalid numbering input on the field and changes nothing', function (array $overrides, string $field) {
    $this->actingAs($this->f->admin);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('configureNumbering')
        ->callAction('configureNumbering', suratNumberingData($overrides))
        ->assertHasFormErrors([$field]);

    expect(Setting::query()->where('key', 'like', 'letters.%')->count())->toBe(0);
})->with([
    'unknown token' => [['number_format' => '{seq}/{foo}/{type_code}/{year}'], 'number_format'],
    'missing token' => [['number_format' => '{seq}/{year}'], 'number_format'],
    'bad school code' => [['school_code' => 'MA K'], 'school_code'],
    'bad padding' => [['sequence_padding' => 9], 'sequence_padding'],
]);

it('AC-37: reports duplicate type codes', function () {
    $this->actingAs($this->f->admin);

    $component = Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('configureNumbering')
        ->callAction('configureNumbering', suratNumberingData(['type_codes' => ['pindah' => 'SKA']]));

    expect(dokumenSeeMessage($component, 'type_codes_not_unique'))->toBeTrue()
        ->and(Setting::query()->where('key', 'like', 'letters.%')->count())->toBe(0);
});

it('AC-37: reports a locked type code', function () {
    suratIssue($this->f);
    $this->actingAs($this->f->admin);

    $component = Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('configureNumbering')
        ->callAction('configureNumbering', suratNumberingData(['type_codes' => ['keterangan_aktif' => 'SKX']]));

    expect(dokumenSeeMessage($component, 'type_code_locked'))->toBeTrue()
        ->and(Setting::query()->where('key', 'like', 'letters.%')->count())->toBe(0);
});

it('AC-37: previews the next letter number', function () {
    $this->actingAs($this->f->admin);

    $component = Livewire::test(ListIssuedLetters::class)
        ->mountAction('configureNumbering');

    expect(tagihanPlainText($component))->toContain('045/MAK/SKA/X/2026');

    $component->fillForm(['number_format' => '{type_code}-{seq}/{year}']);

    expect(tagihanPlainText($component))->toContain('SKA-045/2026');
});

it('AC-37: hides the numbering action from a user without the permission', function () {
    $role = Role::create(['name' => 'penerbit-surat', 'guard_name' => 'web']);
    $role->givePermissionTo(['view-any:issued-letter', 'view:issued-letter', 'create:issued-letter', 'cancel:issued-letter']);
    $user = suratUser('musyrif');
    $user->syncRoles([$role]);
    $this->actingAs($user);

    Livewire::test(ListIssuedLetters::class)
        ->assertActionVisible('issue')
        ->assertActionHidden('configureNumbering');
});
