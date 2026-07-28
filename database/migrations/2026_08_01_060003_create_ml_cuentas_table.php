<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_cuentas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ml_user_id')->unique();
            $table->string('nickname', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('tipo_cuenta', 50)->nullable();
            $table->string('site_id', 5);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expira_en')->nullable();
            $table->string('estado', 20)->default('desconectada');
            $table->timestamp('pendiente_expira_en')->nullable();
            $table->timestamp('vinculada_en')->nullable();
            $table->timestamp('ultimo_refresh_en')->nullable();
            $table->string('ultimo_error', 255)->nullable();
            $table->foreignId('vinculada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_cuentas');
    }
};
