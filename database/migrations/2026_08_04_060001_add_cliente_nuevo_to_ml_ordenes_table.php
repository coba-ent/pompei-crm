<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aviso NO bloqueante: el comprador de la orden no coincide con ningún Cliente
 * del CRM, así que al convertirla se va a dar de alta uno nuevo (FR-037).
 *
 * Deliberadamente separado de `motivo`, que es el campo de los motivos que
 * BLOQUEAN la conversión: acá la orden sigue siendo convertible, sólo se avisa
 * para que el usuario lo revise si quiere. Decisión del usuario (28/07/2026):
 * el producto sin vincular sí bloquea (afecta stock y plata), el cliente no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_ordenes', function (Blueprint $table) {
            $table->boolean('cliente_nuevo')->default(false)->after('comprador_condicion_iva');
        });
    }

    public function down(): void
    {
        Schema::table('ml_ordenes', function (Blueprint $table) {
            $table->dropColumn('cliente_nuevo');
        });
    }
};
