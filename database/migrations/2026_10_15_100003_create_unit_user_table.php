<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cakupan jenjang. User tanpa baris di sini boleh melihat semua jenjang.
        Schema::create('unit_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_user');
    }
};
