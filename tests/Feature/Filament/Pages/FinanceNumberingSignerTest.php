<?php

use App\Filament\Pages\Settings\FinanceNumbering;
use App\Models\Setting;
use App\Models\Staff;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->formats = ['invoice_format' => 'TGH/{YY}/{NNNN}', 'receipt_format' => 'KW/{YY}/{NNNN}'];
    $this->staff = Staff::factory()->create(['name' => 'Ustadz Yusuf', 'position' => 'Bendahara']);
});

it('AC-9: lists only active staff, saves the signer, logs it and can clear it', function () {
    $inactive = Staff::factory()->create(['name' => 'Ustadz Nonaktif', 'is_active' => false]);
    $this->actingAs(kontenUser('bendahara'));
    Activity::query()->delete();

    Livewire::test(FinanceNumbering::class)
        ->assertFormFieldExists('receipt_signer_staff_id', function (Select $field) use ($inactive): bool {
            $options = $field->getSearchResults('');

            return array_key_exists($this->staff->id, $options) && ! array_key_exists($inactive->id, $options);
        })
        ->fillForm([...$this->formats, 'receipt_signer_staff_id' => $this->staff->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('keuangan.receipt_signer_staff_id'))->toBe($this->staff->id)
        ->and(Activity::query()->where('event', 'receipt_signer_updated')->count())->toBe(1);

    Livewire::test(FinanceNumbering::class)
        ->assertFormSet(['receipt_signer_staff_id' => $this->staff->id])
        ->fillForm([...$this->formats, 'receipt_signer_staff_id' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('keuangan.receipt_signer_staff_id'))->toBeNull();
});

it('AC-9: forbids roles without the finance numbering permission', function () {
    $this->actingAs(kontenUser('admin-sekolah'));

    Livewire::test(FinanceNumbering::class)->assertForbidden();

    expect(Setting::get('keuangan.receipt_signer_staff_id'))->toBeNull();
});

it('AC-9: shows an empty select when the stored staff is inactive', function () {
    Setting::set('keuangan.receipt_signer_staff_id', $this->staff->id);
    $this->staff->update(['is_active' => false]);
    $this->actingAs(kontenUser('bendahara'));

    Livewire::test(FinanceNumbering::class)->assertFormSet(['receipt_signer_staff_id' => null]);
});

it('AC-10: saving only the number format does not log a signer change', function () {
    Setting::set('keuangan.receipt_signer_staff_id', $this->staff->id);
    $this->actingAs(kontenUser('bendahara'));
    Activity::query()->delete();

    Livewire::test(FinanceNumbering::class)
        ->fillForm(['invoice_format' => 'TGH/{YY}/{NNNN}', 'receipt_format' => 'KT/{YY}/{NNNN}'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Activity::query()->where('event', 'receipt_signer_updated')->count())->toBe(0)
        ->and(Setting::get('keuangan.receipt_signer_staff_id'))->toBe($this->staff->id)
        ->and(Activity::query()->where('event', 'numbering_updated')->count())->toBe(1);
});

it('AC-10: changing the signer keeps the number formats', function () {
    $this->actingAs(kontenUser('bendahara'));

    Livewire::test(FinanceNumbering::class)
        ->fillForm(['invoice_format' => 'TGH/{YY}/{NNNN}', 'receipt_format' => 'KT/{YY}/{NNNN}', 'receipt_signer_staff_id' => $this->staff->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('keuangan.receipt_signer_staff_id'))->toBe($this->staff->id)
        ->and(Setting::get('keuangan.receipt_format'))->toBe('KT/{YY}/{NNNN}');
});

it('AC-10: a stale inactive signer is cleared and logged when the page is saved', function () {
    Setting::set('keuangan.receipt_signer_staff_id', $this->staff->id);
    $this->staff->update(['is_active' => false]);
    $this->actingAs(kontenUser('bendahara'));
    Activity::query()->delete();

    Livewire::test(FinanceNumbering::class)->fillForm($this->formats)->call('save')->assertHasNoFormErrors();

    expect(Setting::get('keuangan.receipt_signer_staff_id'))->toBeNull()
        ->and(Activity::query()->where('event', 'receipt_signer_updated')->count())->toBe(1);
});

it('VERIFY F062: a forged or inactive staff id is rejected and nothing is saved', function (string $case) {
    $id = $case === 'missing' ? $this->staff->id + 999 : Staff::factory()->create(['is_active' => false])->id;
    $this->actingAs(kontenUser('bendahara'));
    Activity::query()->delete();

    Livewire::test(FinanceNumbering::class)
        ->fillForm([...$this->formats, 'receipt_signer_staff_id' => $id])
        ->call('save');

    expect(Setting::get('keuangan.receipt_signer_staff_id'))->toBeNull()
        ->and(Setting::get('keuangan.receipt_format'))->toBe('KW-{YYYY}{MM}-{NNNNN}')
        ->and(Activity::query()->count())->toBe(0);
})->with(['missing', 'inactive']);
