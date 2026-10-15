<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot karena kakak-adik sering berbagi wali yang sama.
        Schema::create('student_guardian', function (Blueprint $table) {
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->enum('relation', ['ayah', 'ibu', 'wali']);
            $table->boolean('is_billing_contact')->default(false);
            $table->timestamps();

            $table->primary(['student_id', 'guardian_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_guardian');
    }
};
