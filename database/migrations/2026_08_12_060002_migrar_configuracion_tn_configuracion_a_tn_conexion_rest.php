<?php

use App\Models\Integraciones\TiendanubeConexionRest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migración de datos (spec 024, data-model.md §1): copia la configuración de
 * negocio vigente de la fila única de `tn_configuracion` a la fila única de
 * `tn_conexion_rest`, antes de que la Historia 3 elimine la tabla origen.
 * Idempotente (spec.md Assumptions): sobreescritura directa de columnas por
 * `id=1`, no inserción — reintentar esta migración no duplica ni corrompe
 * nada. `down()` no revierte el copiado: no es destructivo, sólo deja de
 * tener sentido una vez que `tn_configuracion` ya no existe.
 */
return new class extends Migration
{
    private const CAMPOS = [
        'modo_solo_lectura', 'creacion_automatica', 'frecuencia_sync_minutos',
        'deposito_id', 'categoria_venta_id', 'cuenta_tesoreria_id', 'dias_primera_sync',
        'ultima_sync_en', 'ultima_sync_resultado', 'stock_ultima_sync_en',
        'stock_ultima_sync_resultado', 'lista_precio_id', 'vendedor_id',
    ];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('tn_configuracion')) {
            return;
        }

        $configuracion = DB::table('tn_configuracion')->first();

        if (! $configuracion) {
            return;
        }

        $conexion = TiendanubeConexionRest::actual();

        $valores = [];
        foreach (self::CAMPOS as $campo) {
            $valores[$campo] = $configuracion->{$campo} ?? null;
        }

        $conexion->forceFill($valores)->save();
    }

    public function down(): void
    {
        // No reversible: ver comentario de clase.
    }
};
