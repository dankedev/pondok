<?php

namespace App\Filament\Concerns;

use App\Models\Halaqah;
use App\Models\HalaqahMember;
use App\Models\Staff;
use App\Models\Student;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Pilihan form setoran dan ujian: halaqah aktif yang terlihat oleh user, anggota aktif halaqah itu,
 * dan staff aktif. Opsi di luar daftar ditolak validasi Select; Action tetap memeriksa ulang.
 */
trait TahfidzFormOptions
{
    /**
     * @return list<Select>
     */
    protected static function halaqahAndStudentFields(): array
    {
        return [
            Select::make('halaqah_id')
                ->label(__('pondok.tahfidz.fields.halaqah_id'))
                ->options(fn (): array => Halaqah::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->live(),
            Select::make('student_id')
                ->label(__('pondok.tahfidz.fields.student_id'))
                ->helperText(__('pondok.tahfidz.record.student_helper'))
                ->options(fn (Get $get): array => Student::query()
                    ->whereIn('id', HalaqahMember::query()->where('halaqah_id', $get('halaqah_id'))->whereNull('left_on')->select('student_id'))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),
        ];
    }

    protected static function staffField(string $name): Select
    {
        return Select::make($name)
            ->label(__("pondok.tahfidz.fields.{$name}"))
            ->options(fn (): array => Staff::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
            ->default(fn (): ?int => auth()->user()->staff?->id)
            ->searchable()
            ->required();
    }

    /**
     * Filter halaqah dengan opsi yang sama dengan form.
     *
     * @return array<int, string>
     */
    protected static function visibleHalaqahOptions(): array
    {
        return Halaqah::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id')->all();
    }
}
