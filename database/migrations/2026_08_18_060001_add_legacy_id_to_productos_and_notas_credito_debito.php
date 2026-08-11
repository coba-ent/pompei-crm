<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `legacy_id` en productos y en notas_credito_debito, para la migración 2021-2026.
 *
 * `ventas` ya lo tenía. Es la clave de idempotencia del importador: permite recorrerlo tantas veces
 * como haga falta sin duplicar nada, que es lo que vuelve seguro un import de 24.000 comprobantes.
 *
 * Formato: `{año}-{Id del Excel}` — el Id de Contagram se repite entre años, por eso lleva el año.
 * En productos no hay años de por medio, así que va el Id pelado.
 *
 * Ver docs/importacion_2021_2026_plan_tecnico.md §4.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('legacy_id', 40)->nullable()->unique()->after('id');
        });

        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->string('legacy_id', 40)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });

        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
