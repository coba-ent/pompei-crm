<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una Venta puede venir de **varias** órdenes de Mercado Libre.
 *
 * El índice único sobre `venta_id` modelaba una regla que no es cierta en este negocio: cuando llegaban
 * dos órdenes del mismo producto y el mismo comprador, en Contagram convertían **una sola** en Venta y le
 * ponían la cantidad total. La otra orden quedaba "Pendiente" para siempre, facturada pero sin vínculo.
 *
 * Eso no era sólo un dato prolijo de menos: sin `venta_id`, `EvaluadorConvertibilidad` recalcula el estado
 * en cada corrida, no encuentra nada malo en la orden —está pagada, con su publicación vinculada— y la
 * devuelve a `lista`. Con la creación automática encendida, el cron le crearía una Venta **duplicada**.
 * Marcarla a mano no alcanza: la marca se pisa en la sincronización siguiente.
 *
 * Con el vínculo cargado, la guarda que ya existe en el evaluador la protege sola y para siempre:
 *
 *     if ($orden->venta_id) { return [EstadoConversion::Convertida, null, null]; }
 *
 * La protección anti-duplicados (spec 038) no se debilita: `ventas.ml_order_id` sigue siendo único, y la
 * guarda por `venta_id` del conversor se evalúa **antes** que la que busca por `ml_order_id`, así que una
 * orden ya vinculada se rechaza igual aunque su id no sea el que quedó grabado en la Venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_ordenes', function (Blueprint $table) {
            // La FK se apoya en este índice, así que hay que soltarla antes de borrarlo y volver a crearla.
            $table->dropForeign(['venta_id']);
            $table->dropUnique('ml_ordenes_venta_id_unique');

            $table->index('venta_id');
            $table->foreign('venta_id')->references('id')->on('ventas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ml_ordenes', function (Blueprint $table) {
            $table->dropForeign(['venta_id']);
            $table->dropIndex(['venta_id']);

            $table->unique('venta_id');
            $table->foreign('venta_id')->references('id')->on('ventas')->nullOnDelete();
        });
    }
};
