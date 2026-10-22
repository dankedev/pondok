<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token undangan akun portal wali (F081), bentuk sama dengan `password_reset_tokens`.
     */
    public function up(): void
    {
        Schema::create('guardian_invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_invitation_tokens');
    }
};
