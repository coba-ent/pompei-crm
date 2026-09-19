<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 105: datos de contacto del emisor para el encabezado de los comprobantes impresos.
 *
 * Puramente aditiva: dos columnas nullable sobre una tabla de fila única. La fila existente queda
 * con ambas en NULL, que es el estado "todavía no lo cargaron" que el partial ya sabe no imprimir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('datos_empresa', function (Blueprint $table) {
            $table->string('telefono')->nullable()->after('ingresos_brutos');
            $table->string('sitio_web')->nullable()->after('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('datos_empresa', function (Blueprint $table) {
            $table->dropColumn(['telefono', 'sitio_web']);
        });
    }
};
