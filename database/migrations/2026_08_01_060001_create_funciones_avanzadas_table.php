<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funciones_avanzadas', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 255);
            $table->string('icono', 50)->nullable();
            $table->unsignedSmallInteger('orden');
            $table->boolean('disponible')->default(false);
            $table->boolean('activa')->default(false);
            $table->string('ruta_configuracion', 150)->nullable();
            $table->foreignId('actualizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actualizada_en')->nullable();
            $table->timestamps();

            $table->index('orden');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funciones_avanzadas');
    }
};
