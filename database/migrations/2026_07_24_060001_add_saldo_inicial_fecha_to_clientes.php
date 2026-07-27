<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha del saldo inicial de la cuenta corriente del cliente (Contagram: al
 * cargar el "Saldo Inicial" se indica Fecha + Monto). Es la fecha de apertura
 * de la cuenta corriente — el punto desde el que arrancan los movimientos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->date('saldo_inicial_fecha')->nullable()->after('saldo_inicial');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('saldo_inicial_fecha');
        });
    }
};
