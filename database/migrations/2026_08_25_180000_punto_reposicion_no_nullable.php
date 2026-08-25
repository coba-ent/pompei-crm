<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `productos.punto_reposicion` deja de ser nullable: `null` y `0` ya significaban exactamente
 * lo mismo ("el producto no se controla, nunca genera alerta"), y arrastrar dos valores para
 * un mismo significado obligaba a un `?? 0` en cada lectura y a normalizar 0 -> null en cada
 * escritura. Ahora el único valor de "no se controla" es `0`.
 *
 * El backfill va antes del ALTER: MySQL convertiría los NULL a 0 igual al pasar la columna a
 * NOT NULL, pero sólo en modo no estricto — con STRICT_TRANS_TABLES aborta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('productos')->whereNull('punto_reposicion')->update(['punto_reposicion' => 0]);

        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedInteger('punto_reposicion')->default(0)->nullable(false)->change();
        });
    }

    /**
     * Vuelve la columna a nullable. No restaura los NULL originales: son indistinguibles de
     * los 0 que ya existían antes de esta migración, y para la aplicación daban lo mismo.
     */
    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedInteger('punto_reposicion')->nullable()->default(null)->change();
        });
    }
};
