<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec 065 — Depósito para publicaciones y órdenes Full de Mercado Libre.
 *
 * `logistic_type` es el único indicador confiable de Full (research R1); `inventory_id`
 * NO sirve para detectarlo y se usa sólo como clave de deduplicación de existencias
 * (FR-009b). Ambos conservan el nombre crudo de la API, siguiendo el precedente de
 * `listing_type_id` (spec 050).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $tabla): void {
            $tabla->string('logistic_type', 40)->nullable()->index()->after('listing_type_sincronizado_en');
            $tabla->string('inventory_id', 40)->nullable()->index()->after('logistic_type');
            $tabla->dateTime('logistica_sincronizada_en')->nullable()->after('inventory_id');
        });

        Schema::table('ml_configuracion', function (Blueprint $tabla): void {
            $tabla->foreignId('deposito_full_id')->nullable()->after('deposito_id')
                ->constrained('depositos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $tabla): void {
            $tabla->dropConstrainedForeignId('deposito_full_id');
        });

        Schema::table('ml_publicacion_producto', function (Blueprint $tabla): void {
            $tabla->dropIndex(['logistic_type']);
            $tabla->dropIndex(['inventory_id']);
            $tabla->dropColumn(['logistic_type', 'inventory_id', 'logistica_sincronizada_en']);
        });
    }
};
