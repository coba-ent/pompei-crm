<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 084 — interruptor de activación del corte de precios.
 *
 * **Nace por defecto apagado, y es imprescindible que así sea.** El corte retiene cuando no conoce
 * el precio publicado (`precio_publicado = null`), que es el estado de las 270 publicaciones el día
 * del deploy: si se activara solo, el primer cambio de precio retendría absolutamente todo y la
 * reacción natural sería desactivarlo y desconfiar de la feature.
 *
 * El orden de activación (research.md Decisión 5) es: migrar → poblar `precio_publicado` con
 * `ml:chequear-precios --refrescar-publicado` → verificar en el monitoreo → recién ahí activar.
 * Esta columna es lo que hace que ese orden sea imposible de saltear por accidente.
 *
 * **No es un kill-switch para apagar la protección** cuando el corte moleste: para eso está el
 * umbral. Si se apaga esto, el CRM vuelve a publicar cualquier precio sin validar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->boolean('corte_precios_activo')->default(false)->after('umbral_caida_precio_pct');
        });
    }

    public function down(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->dropColumn('corte_precios_activo');
        });
    }
};
