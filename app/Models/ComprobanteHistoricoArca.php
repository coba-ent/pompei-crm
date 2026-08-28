<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * spec 088: 14 comprobantes de venta con CAE real de ARCA que quedaron fuera de la base actual.
 * Sólo lectura — sin relaciones a `Venta`/`Cliente`, sin controlador ni rutas (spec FR-007). Usado
 * por la migración (`Model::insert()`) y por los tests; el resto del sistema lo lee vía
 * {@see \App\Services\Informes\LibroIvaVentasQuery::queryHistoricos()}.
 */
class ComprobanteHistoricoArca extends Model
{
    protected $table = 'comprobantes_historicos_arca';

    protected $fillable = [
        'fecha_emision', 'tipo_comprobante', 'punto_venta', 'numero', 'cae', 'cae_vencimiento',
        'cliente_nombre', 'cliente_documento_tipo', 'cliente_documento_numero',
        'neto_no_gravado', 'neto_exento', 'neto_gravado',
        'iva_2_5', 'iva_5', 'iva_10_5', 'iva_21', 'iva_27',
        'perc_iva', 'perc_iibb', 'imp_internos', 'imp_municipales',
        'total', 'origen',
    ];
}
