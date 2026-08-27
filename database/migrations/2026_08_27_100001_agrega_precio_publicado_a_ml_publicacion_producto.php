<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 084 T002 — referencia contra la que el corte mide la caída de precio.
 *
 * `precio_publicado` es el último precio que Mercado Libre **aceptó**: lo escribe cada envío
 * exitoso y lo refresca el chequeo diario contra la realidad de la API.
 *
 * **`null` retiene el envío**, nunca "publicá igual" (research.md Decisión 1). Sin saber qué hay
 * publicado no se puede afirmar que no se está bajando el precio. Al desplegar quedan las 270
 * publicaciones en `null`, por eso el rollout exige poblarlas con
 * `ml:chequear-precios --refrescar-publicado` ANTES de activar el corte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->decimal('precio_publicado', 14, 2)->nullable()->after('precio_error_en');
            $table->timestamp('precio_publicado_en')->nullable()->after('precio_publicado');
        });
    }

    public function down(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->dropColumn(['precio_publicado', 'precio_publicado_en']);
        });
    }
};
