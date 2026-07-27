<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo geográfico de Argentina (dataset oficial georef): provincias y sus
 * localidades. Se usa para poblar los selects linkeados del formulario. En los
 * clientes/proveedores se sigue guardando el NOMBRE (string), no la FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provincias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        Schema::create('localidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provincia_id')->constrained('provincias')->cascadeOnDelete();
            $table->string('nombre');
            $table->timestamps();

            $table->index('provincia_id');
            $table->index('nombre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('localidades');
        Schema::dropIfExists('provincias');
    }
};
