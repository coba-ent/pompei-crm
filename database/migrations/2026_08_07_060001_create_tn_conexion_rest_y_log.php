<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tn_conexion_rest', function (Blueprint $table) {
            $table->id();
            $table->text('access_token')->nullable();
            $table->string('store_id', 50)->nullable();
            $table->string('scopes_otorgados', 255)->nullable();
            $table->string('tienda_nombre', 255)->nullable();
            $table->string('tienda_dominio', 255)->nullable();
            $table->string('estado', 20)->default('no_configurada');
            $table->text('ultimo_error')->nullable();
            $table->timestamp('conectada_en')->nullable();
            $table->foreignId('actualizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tn_rest_operaciones_log', function (Blueprint $table) {
            $table->id();
            $table->string('operacion');
            $table->string('metodo');
            $table->string('endpoint');
            $table->string('sentido');
            $table->string('resultado');
            $table->integer('codigo_http')->nullable();
            $table->integer('duracion_ms')->nullable();
            $table->text('mensaje_error')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tn_rest_operaciones_log');
        Schema::dropIfExists('tn_conexion_rest');
    }
};
