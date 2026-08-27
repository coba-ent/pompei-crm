<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 084 T001 — umbral del corte de seguridad de precios.
 *
 * Caída porcentual máxima que se publica en Mercado Libre sin aprobación humana. Rango 0–100:
 * `0` retiene toda bajada, `100` no retiene por porcentaje pero **sigue** reteniendo precio ≤ 0 y
 * precio publicado desconocido — no es un interruptor de apagado del corte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->decimal('umbral_caida_precio_pct', 5, 2)->default(20.00)->after('modo_solo_lectura');
        });
    }

    public function down(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->dropColumn('umbral_caida_precio_pct');
        });
    }
};
