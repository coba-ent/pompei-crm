<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Retiro de la integración MCP de Tiendanube (spec 024, Historia 3, T025):
 * la configuración de negocio y el token de la conexión OAuth/MCP ya fueron
 * migrados a `tn_conexion_rest` (2026_08_12_060002). Las sincronizaciones de
 * órdenes/stock/precios y la vinculación automática corren sobre
 * `ClienteTiendanubeRest` desde spec 024 Historias 1 y 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tn_configuracion');
        Schema::dropIfExists('tn_operaciones_log');
    }

    public function down(): void
    {
        // No reversible: las tablas MCP no vuelven a crearse (ver comentario de clase).
    }
};
