<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Izin santri (F024). Status = enum PHP `LeaveRequestStatus`; "terlambat" dihitung, tidak disimpan.
        // `approved_by` ke `users` (bukan `staff` seperti ERD awal) dan juga diisi saat ditolak.
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('homecoming_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('requested_via');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->dateTime('planned_departure');
            $table->dateTime('return_due');
            $table->string('status');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('pickup_name')->nullable();
            $table->string('pickup_phone', 30)->nullable();
            $table->dateTime('departed_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('returned_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'return_due']);
            $table->index(['student_id', 'status']);
            // Izin biasa memakai `homecoming_period_id` NULL; NULL tidak bentrok di unique (SQLite/MySQL/Postgres).
            $table->unique(['student_id', 'homecoming_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
