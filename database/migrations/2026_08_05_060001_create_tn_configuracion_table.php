<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tn_configuracion', function (Blueprint $table) {
            $table->id();
            $table->string('store_id', 50)->nullable();
            $table->text('access_token')->nullable();
            $table->string('nombre_tienda', 150)->nullable();
            $table->string('dominio', 150)->nullable();
            $table->string('pais', 5)->nullable();
            $table->string('moneda', 10)->nullable();
            $table->string('estado', 20)->default('desconectada');
            $table->text('ultimo_error')->nullable();
            $table->boolean('modo_solo_lectura')->default(false);
            $table->timestamp('credenciales_guardadas_en')->nullable();
            $table->timestamp('ultima_verificacion_en')->nullable();
            $table->foreignId('actualizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tn_configuracion');
    }
};
