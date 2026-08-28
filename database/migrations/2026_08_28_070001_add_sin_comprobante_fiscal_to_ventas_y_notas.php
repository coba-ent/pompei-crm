<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca las ventas/notas migradas que en Contagram NO tenían comprobante fiscal emitido.
 *
 * El Libro IVA es un registro de **comprobantes fiscales**, no de ventas: Contagram sólo lista ahí
 * los que tienen letra (`FCA`/`FCB`/`NCB`…) y deja fuera los de `Tipo de Comprobante` sin letra
 * (`FC`/`NC`/`ND`/vacío, que en el export van siempre con `ARCA = ---`). Son ventas internas sin
 * factura emitida.
 *
 * La migración histórica les asignó igual `tipo_comprobante = 'B'`, así que en la base dejaron de
 * distinguirse de una Factura B real — por eso el Libro IVA del CRM mostraba ~39% de comprobantes de
 * más que Contagram incluso con las dos casillas del filtro tildadas.
 *
 * Se agrega una columna propia en vez de poner `tipo_comprobante = NULL`: esa columna la leen 141
 * lugares del sistema (validaciones, PDFs, emisión ARCA, exports) que asumen que siempre hay letra.
 * El flag es aditivo y nadie que no lo consulte cambia de comportamiento.
 *
 * La poblá `migracion:marcar-sin-comprobante-fiscal`, que recupera el dato del Excel de origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->boolean('sin_comprobante_fiscal')->default(false)->after('nro_comprobante');
            $table->index('sin_comprobante_fiscal');
        });

        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->boolean('sin_comprobante_fiscal')->default(false)->after('nro_comprobante');
            $table->index('sin_comprobante_fiscal');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex(['sin_comprobante_fiscal']);
            $table->dropColumn('sin_comprobante_fiscal');
        });

        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->dropIndex(['sin_comprobante_fiscal']);
            $table->dropColumn('sin_comprobante_fiscal');
        });
    }
};
