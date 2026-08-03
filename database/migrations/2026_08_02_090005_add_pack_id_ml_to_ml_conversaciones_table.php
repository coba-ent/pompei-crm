<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige un bug real detectado en producción (02/08/2026, primer mensaje post-venta real):
 * `EnvioRespuestaMercadoLibre` armaba la URL de envío a partir de
 * `ml_conversaciones.ml_orden_id`, pero esa FK sólo existe si la orden YA fue sincronizada
 * al CRM (`ml_ordenes`) — un pack_id real de ML puede no tener orden sincronizada todavía
 * (webhook de mensaje llega antes que el cron de sincronización de órdenes, o la orden nunca
 * se sincroniza). Sin el `pack_id` crudo, el envío queda con un pack_id vacío → 404 de ML.
 *
 * `pack_id_ml` guarda el pack/order id crudo de ML (igual patrón que `publicacion_id_ml` para
 * Preguntas — dato de ML independiente de si existe o no el vínculo al CRM). También pasa a
 * ser la clave de deduplicación real para post-venta: dos packs distintos sin orden
 * sincronizada (`ml_orden_id` NULL en ambos) antes se pisaban en el mismo `firstOrCreate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_conversaciones', function (Blueprint $table) {
            $table->dropUnique('ml_conversaciones_post_venta_unica');
            $table->string('pack_id_ml', 40)->nullable()->after('ml_orden_id');
        });

        Schema::table('ml_conversaciones', function (Blueprint $table) {
            $table->unique(['tipo', 'pack_id_ml'], 'ml_conversaciones_post_venta_unica');
        });
    }

    public function down(): void
    {
        Schema::table('ml_conversaciones', function (Blueprint $table) {
            $table->dropUnique('ml_conversaciones_post_venta_unica');
            $table->dropColumn('pack_id_ml');
        });

        Schema::table('ml_conversaciones', function (Blueprint $table) {
            $table->unique(['tipo', 'ml_orden_id'], 'ml_conversaciones_post_venta_unica');
        });
    }
};
