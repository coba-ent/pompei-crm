<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El CUIT/DNI no es único entre clientes ni proveedores (decisión 31/07/2026):
 * nada del sistema matchea registros por CUIT (Mercado Libre/Tiendanube lo
 * hacen por ml_user_id/apodo; ARCA/CAE todavía no está implementado), y exigir
 * unicidad bloqueaba migrar datos reales con duplicados legítimos del sistema
 * anterior. La regla de validación de Laravel ya se sacó de
 * ReglasCliente/ReglasProveedor; esta migración saca el índice único
 * subyacente en la base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropUnique(['cuit']);
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropUnique(['cuit']);
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->unique('cuit');
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->unique('cuit');
        });
    }
};
