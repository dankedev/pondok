<?php

use App\Models\Staff;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->f = suratFixture();
});

it('AC-1: creates issued letters with ERD columns and indexes', function () {
    expect(Schema::hasColumns('issued_letters', [
        'id', 'letter_no', 'type', 'sequence_year', 'sequence', 'student_id', 'signed_by_staff_id', 'issued_on', 'purpose',
        'verification_code', 'snapshot', 'file_path', 'status', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'issued_by',
        'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('issued_letters', 'deleted_at'))->toBeFalse();

    $indexes = collect(Schema::getIndexes('issued_letters'));
    $unique = $indexes->where('unique', true)->pluck('columns')->all();
    $plain = $indexes->where('unique', false)->pluck('columns')->all();

    expect($unique)->toContain(['letter_no'])
        ->toContain(['verification_code'])
        ->toContain(['type', 'sequence_year', 'sequence'])
        ->and($plain)->toContain(['student_id', 'type'])
        ->toContain(['status', 'issued_on']);
});

it('AC-1: restricts every foreign key', function () {
    $keys = collect(Schema::getForeignKeys('issued_letters'));

    foreach (['student_id' => 'students', 'signed_by_staff_id' => 'staff', 'issued_by' => 'users', 'cancelled_by' => 'users'] as $column => $table) {
        $key = $keys->first(fn (array $key): bool => $key['columns'] === [$column]);

        expect($key)->not->toBeNull("FK {$column}")
            ->and($key['foreign_table'])->toBe($table)
            ->and(strtolower($key['on_delete']))->toBe('restrict');
    }
});

it('AC-1: defaults the status to terbit and lets file_path stay empty', function () {
    $id = DB::table('issued_letters')->insertGetId(suratRow($this->f));
    $row = DB::table('issued_letters')->where('id', $id)->first();

    expect($row->status)->toBe('terbit')
        ->and($row->file_path)->toBeNull()
        ->and($row->cancelled_at)->toBeNull()
        ->and($row->cancelled_by)->toBeNull()
        ->and($row->cancel_reason)->toBeNull();
});

it('AC-1: stores the snapshot as json', function () {
    $id = DB::table('issued_letters')->insertGetId(suratRow($this->f));

    expect(json_decode((string) DB::table('issued_letters')->where('id', $id)->value('snapshot'), true))
        ->toHaveKeys(['student', 'signer', 'school']);
});

it('AC-2: rejects a duplicate letter number', function () {
    DB::table('issued_letters')->insert(suratRow($this->f, ['letter_no' => '001/MAK/SKA/X/2026', 'sequence' => 1]));

    expect(fn () => DB::table('issued_letters')->insert(suratRow($this->f, ['letter_no' => '001/MAK/SKA/X/2026', 'sequence' => 2])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('issued_letters')->count())->toBe(1);
});

it('AC-2: rejects a duplicate sequence for the same type and year but allows other types and years', function () {
    DB::table('issued_letters')->insert(suratRow($this->f, ['sequence' => 1]));

    expect(fn () => DB::table('issued_letters')->insert(suratRow($this->f, ['sequence' => 1])))
        ->toThrow(UniqueConstraintViolationException::class);

    DB::table('issued_letters')->insert(suratRow($this->f, ['sequence' => 1, 'type' => 'pindah']));
    DB::table('issued_letters')->insert(suratRow($this->f, ['sequence' => 1, 'sequence_year' => 2027]));

    expect(DB::table('issued_letters')->count())->toBe(3);
});

it('AC-2: rejects a duplicate verification code', function () {
    DB::table('issued_letters')->insert(suratRow($this->f, ['verification_code' => 'ABCDEFGHJKMN']));

    expect(fn () => DB::table('issued_letters')->insert(suratRow($this->f, ['verification_code' => 'ABCDEFGHJKMN'])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('issued_letters')->count())->toBe(1);
});

it('AC-2: refuses to force delete a student that has a letter', function () {
    $student = Student::factory()->forCohort($this->f->cohortSd)->create();
    $id = DB::table('issued_letters')->insertGetId(suratRow($this->f, ['student_id' => $student->id]));

    expect(fn () => $student->forceDelete())->toThrow(QueryException::class)
        ->and(Student::withTrashed()->find($student->id))->not->toBeNull();

    DB::table('issued_letters')->where('id', $id)->delete();

    $student->forceDelete();

    expect(Student::withTrashed()->find($student->id))->toBeNull();
});

it('AC-2: refuses to delete a signing staff member', function () {
    $staff = Staff::factory()->create();
    $id = DB::table('issued_letters')->insertGetId(suratRow($this->f, ['signed_by_staff_id' => $staff->id]));

    expect(fn () => DB::table('staff')->where('id', $staff->id)->delete())->toThrow(QueryException::class)
        ->and(Staff::query()->find($staff->id))->not->toBeNull();

    DB::table('issued_letters')->where('id', $id)->delete();
    DB::table('staff')->where('id', $staff->id)->delete();

    expect(Staff::query()->find($staff->id))->toBeNull();
});

it('AC-2: refuses to delete the user who issued or cancelled a letter', function () {
    $issuer = suratUser('admin-sekolah');
    $canceller = suratUser('admin-sekolah');
    DB::table('issued_letters')->insert(suratRow($this->f, ['issued_by' => $issuer->id, 'status' => 'batal', 'cancelled_by' => $canceller->id, 'cancelled_at' => now(), 'cancel_reason' => 'Salah']));

    expect(fn () => DB::table('users')->where('id', $issuer->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('users')->where('id', $canceller->id)->delete())->toThrow(QueryException::class);
});
