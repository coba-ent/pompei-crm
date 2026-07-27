<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de cuentas de dinero del negocio (spec 007 — Tesorería).
 * El saldo NO se almacena: es derivado de `movimientos_tesoreria` (data-model §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_tesoreria', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->enum('tipo', ['a_cobrar', 'a_pagar', 'banco', 'efectivo']);
            $table->boolean('visible')->default(true);
            $table->boolean('es_sistema')->default(false);
            $table->decimal('saldo_inicial', 14, 2)->default(0);
            $table->date('saldo_inicial_fecha')->nullable();
            $table->smallInteger('orden')->nullable();
            $table->timestamps();

            $table->index('tipo');
            $table->index('visible');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_tesoreria');
    }
};
