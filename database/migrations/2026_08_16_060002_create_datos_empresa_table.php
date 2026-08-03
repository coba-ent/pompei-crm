<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datos_empresa', function (Blueprint $table) {
            $table->id();
            $table->string('razon_social')->nullable();
            $table->string('cuit', 11)->nullable();
            $table->string('domicilio_fiscal')->nullable();
            $table->string('condicion_iva')->nullable();
            $table->string('ingresos_brutos')->nullable();
            $table->string('ruta_logo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datos_empresa');
    }
};
