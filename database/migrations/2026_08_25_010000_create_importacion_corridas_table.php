<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacion_corridas', function (Blueprint $table) {
            $table->id();
            $table->string('entidad');
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archivo_original');
            $table->dateTime('confirmado_en');
            $table->dateTime('deshacer_disponible_hasta');
            $table->unsignedInteger('filas_creadas')->default(0);
            $table->unsignedInteger('filas_actualizadas')->default(0);
            $table->unsignedInteger('filas_fallidas')->default(0);
            $table->dateTime('deshecho_en')->nullable();
            $table->foreignId('deshecho_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('filas_revertidas')->nullable();
            $table->unsignedInteger('filas_no_revertidas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacion_corridas');
    }
};
