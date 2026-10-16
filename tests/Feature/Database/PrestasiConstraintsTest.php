<?php

use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->f = prestasiFixture();
});

it('AC-1: creates achievement tables from the ERD', function () {
    expect(Schema::hasColumns('achievements', [
        'id', 'academic_year_id', 'category', 'title', 'event_name', 'organizer', 'level', 'kind', 'achieved_on',
        'recorded_by', 'verified_by', 'is_public', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('achievements', 'deleted_at'))->toBeFalse()
        ->and(class_uses_recursive(Achievement::class))->not->toContain(SoftDeletes::class);

    expect(Schema::hasColumns('achievement_student', ['achievement_id', 'student_id', 'rank', 'role']))->toBeTrue()
        ->and(Schema::hasColumn('achievement_student', 'created_at'))->toBeFalse();
});

it('AC-1: indexes the achievements table and keys the pivot', function () {
    $indexes = collect(Schema::getIndexes('achievements'))->pluck('columns')->all();

    expect($indexes)->toContain(['academic_year_id', 'category', 'level'])->toContain(['achieved_on']);

    $pivot = collect(Schema::getIndexes('achievement_student'));

    expect($pivot->firstWhere('primary', true)['columns'] ?? null)->toBe(['achievement_id', 'student_id'])
        ->and($pivot->pluck('columns')->all())->toContain(['student_id']);
});

it('AC-1: sets the foreign key actions', function () {
    $keys = fn (string $table) => collect(Schema::getForeignKeys($table));

    foreach (['academic_year_id' => 'academic_years', 'recorded_by' => 'users', 'verified_by' => 'users'] as $column => $table) {
        $key = $keys('achievements')->first(fn (array $key): bool => $key['columns'] === [$column]);

        expect($key)->not->toBeNull("FK {$column}")
            ->and($key['foreign_table'])->toBe($table)
            ->and(strtolower($key['on_delete']))->toBe('restrict');
    }

    $achievement = $keys('achievement_student')->first(fn (array $key): bool => $key['columns'] === ['achievement_id']);
    $student = $keys('achievement_student')->first(fn (array $key): bool => $key['columns'] === ['student_id']);

    expect($achievement['foreign_table'])->toBe('achievements')
        ->and(strtolower($achievement['on_delete']))->toBe('cascade')
        ->and($student['foreign_table'])->toBe('students')
        ->and(strtolower($student['on_delete']))->toBe('restrict');
});

it('AC-2: enforces achievement constraints', function () {
    $f = $this->f;
    $year = AcademicYear::factory()->create();
    $recorder = User::factory()->create();
    $achievement = prestasiCreate($f, [$f->s2], ['academic_year_id' => $year->id, 'recorded_by' => $recorder->id]);

    expect(fn () => DB::table('academic_years')->where('id', $year->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('users')->where('id', $recorder->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => $f->s2->forceDelete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('achievement_student')->insert(['achievement_id' => $achievement->id, 'student_id' => $f->s2->id, 'rank' => 'Juara 2']))
        ->toThrow(UniqueConstraintViolationException::class);

    $verifier = User::factory()->create();
    DB::table('achievements')->where('id', $achievement->id)->update(['verified_by' => $verifier->id]);

    expect(fn () => DB::table('users')->where('id', $verifier->id)->delete())->toThrow(QueryException::class);
});

it('AC-2: removes the pivot rows with the achievement and defaults is_public to false', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2, $f->s3]);
    $other = prestasiCreate($f, [$f->s2]);

    DB::table('achievements')->where('id', $achievement->id)->delete();

    expect(DB::table('achievement_student')->where('achievement_id', $achievement->id)->count())->toBe(0)
        ->and(DB::table('achievement_student')->where('achievement_id', $other->id)->count())->toBe(1);

    $id = DB::table('achievements')->insertGetId([
        'academic_year_id' => $f->y2->id, 'category' => 'seni', 'title' => 'Tanpa is_public', 'event_name' => 'Lomba', 'level' => 'pondok',
        'kind' => 'individu', 'achieved_on' => '2026-08-17', 'recorded_by' => $f->k->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect((bool) DB::table('achievements')->where('id', $id)->value('is_public'))->toBeFalse()
        ->and((new Achievement)->is_public)->toBeFalse();
});
