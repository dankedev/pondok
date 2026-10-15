<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Penugasan musyrif/kepala asrama per tahun ajaran. `room_id` null = seluruh asrama.
        Schema::create('dormitory_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dormitory_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->enum('role', ['kepala_asrama', 'musyrif']);
            $table->date('start_on');
            $table->date('end_on')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'end_on']);
            $table->index(['dormitory_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dormitory_supervisors');
    }
};
