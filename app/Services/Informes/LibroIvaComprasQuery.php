<?php

namespace App\Services\Informes;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Libro IVA Compras (spec 077, US2): una fila por comprobante (compra o NC/ND de compra) del
 * período elegido, resolviendo el período con `COALESCE(mes_imputacion_iva, fecha_emision)` —
 * FR-009. Sin las casillas ARCA/Manuales (FR-014a): el comprobante lo emite el proveedor.
 */
class LibroIvaComprasQuery extends LibroIvaQuery
{
    private const ALICUOTAS = ['2.5', '5', '10.5', '21', '27'];

    public function __construct(private DesgloseImpositivoCompra $desglose) {}

    public function detalle(Request $request): Builder
    {
        $items = $this->queryCompras($request);
        $notas = $this->queryNotas($request);

        return $notas === null ? $items : $items->unionAll($notas);
    }

    // -----------------------------------------------------------------------------------
    // Rama de compras
    // -----------------------------------------------------------------------------------

    private function queryCompras(Request $request): Builder
    {
        $d = $this->desglose;

        $periodoExpr = 'COALESCE(compras.mes_imputacion_iva, compras.fecha_emision)';

        // `DesgloseImpositivoCompra::sqlNeto()`/`sqlIva()` están escritos para un ítem individual
        // (alias por defecto `compra_items`); acá se envuelven en subqueries correlacionadas para
        // agregar a nivel comprobante — mismo criterio que Ventas (ver LibroIvaQuery).
        $query = $this->envolverEnSubqueryCorrelacionada($d);

        $this->filtrarPeriodo($query, $request, $periodoExpr);
        $this->aplicarFiltrosComunes($query, $request, 'compras.id', 'compras.proveedor_id', 'proveedores.cuit', 'proveedores.condicion_iva_id', 'compras.tipo_comprobante', 'compras.nro_comprobante');
        $this->filtrarMedioTesoreria($query, $request, 'pagos', 'compra_id', 'compras.id');
        $this->filtrarProvincia($query, $request, 'proveedores');

        return $query;
    }

    /**
     * `DesgloseImpositivoCompra::sqlNeto()`/`sqlIva()` devuelven expresiones sobre `compra_items`
     * (alias por defecto), pensadas para un `SELECT` a nivel ítem. Acá se envuelven en
     * `SELECT SUM(...) FROM compra_items ci WHERE ci.compra_id = compras.id` para agregarlas a
     * nivel comprobante, sin reimplementar la clasificación fiscal (research §D2).
     */
    private function envolverEnSubqueryCorrelacionada(DesgloseImpositivoCompra $d): Builder
    {
        $neto = fn (string $clase) => 'COALESCE((SELECT SUM('.$d->sqlNeto($clase, 'ci').') FROM compra_items ci WHERE ci.compra_id = compras.id), 0)';
        $iva = fn (string $a) => 'COALESCE((SELECT SUM('.$d->sqlIva($a, 'ci').') FROM compra_items ci WHERE ci.compra_id = compras.id), 0)';

        $columnas = array_merge([
            'compras.id as id',
            'compras.fecha_emision as emision',
            'compras.tipo_comprobante as tipo',
            'compras.nro_comprobante as nro_comprobante',
            "COALESCE(proveedores.nombre, 'Sin proveedor') as contraparte",
            'proveedores.cuit as cuit',
            "COALESCE(condiciones_iva.nombre, '') as condicion_iva",
            $neto('no_gravado').' as neto_no_gravado',
            $neto('exento').' as neto_exento',
            $neto('gravado').' as neto_gravado',
        ], array_map(
            fn (string $a) => $iva($a).' as iva_'.str_replace('.', '_', $a),
            self::ALICUOTAS
        ), [
            $this->sqlPercIva('compra_conceptos', 'compra_id', 'compras.id').' as perc_iva',
            $this->sqlPercIibb('compra_conceptos', 'compra_id', 'compras.id').' as perc_iibb',
            $this->sqlImpuestosInternos('compra_conceptos', 'compra_id', 'compras.id').' as imp_internos',
            '0 as imp_municipales',
        ]);

        return DB::table('compras')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'compras.proveedor_id')
            ->leftJoin('condiciones_iva', 'condiciones_iva.id', '=', 'proveedores.condicion_iva_id')
            ->whereNull('compras.deleted_at')
            ->selectRaw(implode(', ', $columnas));
    }

    // -----------------------------------------------------------------------------------
    // Rama de NC/ND de compra
    // -----------------------------------------------------------------------------------

    private function queryNotas(Request $request): ?Builder
    {
        [$desde, $hasta] = $this->rangoPeriodo($request);

        $query = DB::table('notas_credito_debito')
            ->join('compras', 'compras.id', '=', 'notas_credito_debito.compra_id')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'compras.proveedor_id')
            ->leftJoin('condiciones_iva', 'condiciones_iva.id', '=', 'proveedores.condicion_iva_id')
            ->whereNull('notas_credito_debito.deleted_at')
            ->whereNull('compras.deleted_at')
            ->whereNotNull('notas_credito_debito.compra_id')
            ->whereDate('notas_credito_debito.mes_imputacion', '>=', $desde)
            ->whereDate('notas_credito_debito.mes_imputacion', '<=', $hasta);

        $this->aplicarFiltrosComunes($query, $request, 'notas_credito_debito.id', 'compras.proveedor_id', 'proveedores.cuit', 'proveedores.condicion_iva_id', null, 'notas_credito_debito.nro_comprobante');
        $this->filtrarMedioTesoreria($query, $request, 'pagos', 'compra_id', 'compras.id');
        $this->filtrarProvincia($query, $request, 'proveedores');

        $notas = $query->select([
            'notas_credito_debito.id', 'notas_credito_debito.compra_id', 'notas_credito_debito.tipo',
            'notas_credito_debito.tipo_comprobante', 'notas_credito_debito.monto', 'notas_credito_debito.impuestos',
            'notas_credito_debito.fecha_emision', 'notas_credito_debito.nro_comprobante',
            'proveedores.nombre as proveedor_nombre', 'proveedores.cuit', 'condiciones_iva.nombre as condicion_iva_nombre',
        ])->get();

        if ($request->filled('tipo_comprobante')) {
            $tiposPedidos = (array) $request->input('tipo_comprobante');
            $notas = $notas->filter(fn ($n) => in_array($this->tipoCompuesto($n), $tiposPedidos, true))->values();
        }

        if ($notas->isEmpty()) {
            return null;
        }

        $builder = null;

        foreach ($notas as $nota) {
            $fila = DB::query()->selectRaw($this->filaLiteralNota($nota));
            $builder = $builder ? $builder->unionAll($fila) : $fila;
        }

        return $builder;
    }

    private function tipoCompuesto($nota): string
    {
        return ($nota->tipo === 'credito' ? 'NC' : 'ND').$nota->tipo_comprobante;
    }

    private function filaLiteralNota($nota): string
    {
        $signo = $nota->tipo === 'credito' ? -1 : 1;
        $monto = (float) $nota->monto;

        [$percIva, $percIibb, $impInternos] = $this->desgloseConceptosNota($nota);
        $desglose = $this->desgloseNota($nota, $monto);

        $tipo = $this->tipoCompuesto($nota);
        $contraparte = $nota->proveedor_nombre ?? 'Sin proveedor';
        $condicionIva = $nota->condicion_iva_nombre ?? '';

        $partes = [
            ((int) $nota->id).' as id',
            ExpresionSql::literal((string) $nota->fecha_emision).' as emision',
            ExpresionSql::literal($tipo).' as tipo',
            ($nota->nro_comprobante !== null ? ExpresionSql::literal($nota->nro_comprobante) : 'NULL').' as nro_comprobante',
            ExpresionSql::literal($contraparte).' as contraparte',
            ($nota->cuit !== null ? ExpresionSql::literal($nota->cuit) : 'NULL').' as cuit',
            ExpresionSql::literal($condicionIva).' as condicion_iva',
            $this->num($signo * $desglose['no_gravado']).' as neto_no_gravado',
            $this->num($signo * $desglose['exento']).' as neto_exento',
            $this->num($signo * $desglose['gravado']).' as neto_gravado',
        ];

        foreach (self::ALICUOTAS as $a) {
            $partes[] = $this->num($signo * ($desglose['iva'][$a] ?? 0)).' as iva_'.str_replace('.', '_', $a);
        }

        $partes[] = $this->num($signo * $percIva).' as perc_iva';
        $partes[] = $this->num($signo * $percIibb).' as perc_iibb';
        $partes[] = $this->num($signo * $impInternos).' as imp_internos';
        $partes[] = '0 as imp_municipales';

        return implode(', ', $partes);
    }

    private function num(float $n): string
    {
        return number_format(round($n, 2), 2, '.', '');
    }

    private function desgloseConceptosNota($nota): array
    {
        $percIva = 0.0;
        $percIibb = 0.0;
        $impInternos = 0.0;

        foreach ((array) ($nota->impuestos ? json_decode($nota->impuestos, true) : []) as $c) {
            $tipo = $c['tipo'] ?? null;
            $monto = (float) ($c['monto'] ?? 0);

            if ($tipo === 'percepcion') {
                $texto = mb_strtolower((string) ($c['concepto'] ?? ''));
                $esIibb = false;
                foreach (self::PALABRAS_IIBB as $palabra) {
                    if (str_contains($texto, $palabra)) {
                        $esIibb = true;
                        break;
                    }
                }
                $esIibb ? $percIibb += $monto : $percIva += $monto;
            } elseif ($tipo === 'impuesto_interno') {
                $impInternos += $monto;
            }
        }

        return [$percIva, $percIibb, $impInternos];
    }

    /** Mismas 4 ramas de precedencia que Ventas (FR-022d), sobre `compra_items`. */
    private function desgloseNota($nota, float $monto): array
    {
        $ivaPorAlicuota = array_fill_keys(self::ALICUOTAS, 0.0);

        $entradasIva = collect((array) ($nota->impuestos ? json_decode($nota->impuestos, true) : []))
            ->filter(fn ($c) => ($c['tipo'] ?? null) === 'iva');

        if ($entradasIva->isNotEmpty()) {
            $noGravado = 0.0;
            $exento = 0.0;
            $gravado = 0.0;

            foreach ($entradasIva as $e) {
                $alicuota = (string) ($e['alicuota'] ?? '');
                $neto = (float) ($e['neto'] ?? 0);
                $iva = array_key_exists('iva', $e) ? (float) $e['iva'] : round($neto * ((float) $alicuota) / 100, 2);

                if (in_array($alicuota, self::ALICUOTAS, true)) {
                    $gravado += $neto;
                    $ivaPorAlicuota[$alicuota] += $iva;
                } elseif ($alicuota === 'exento') {
                    $exento += $neto;
                } else {
                    $noGravado += $neto;
                }
            }

            return ['no_gravado' => $noGravado, 'exento' => $exento, 'gravado' => $gravado, 'iva' => $ivaPorAlicuota];
        }

        $gravadoPorAlicuota = DB::table('compra_items')
            ->where('compra_id', $nota->compra_id)
            ->whereIn('iva_pct', self::ALICUOTAS)
            ->groupBy('iva_pct')
            ->selectRaw('iva_pct, SUM(subtotal) as neto')
            ->pluck('neto', 'iva_pct')
            ->map(fn ($v) => (float) $v)
            ->all();

        if ($gravadoPorAlicuota === []) {
            return ['no_gravado' => $monto, 'exento' => 0.0, 'gravado' => 0.0, 'iva' => $ivaPorAlicuota];
        }

        if (count($gravadoPorAlicuota) === 1) {
            $alicuota = array_key_first($gravadoPorAlicuota);
            $neto = $monto / (1 + ((float) $alicuota) / 100);
            $ivaPorAlicuota[$alicuota] = $monto - $neto;

            return ['no_gravado' => 0.0, 'exento' => 0.0, 'gravado' => $neto, 'iva' => $ivaPorAlicuota];
        }

        $totalNeto = array_sum($gravadoPorAlicuota);
        $gravado = 0.0;

        foreach ($gravadoPorAlicuota as $alicuota => $netoOriginal) {
            $share = $totalNeto > 0 ? $netoOriginal / $totalNeto : 1 / count($gravadoPorAlicuota);
            $montoAlicuota = $monto * $share;
            $netoLinea = $montoAlicuota / (1 + ((float) $alicuota) / 100);
            $ivaPorAlicuota[$alicuota] = $montoAlicuota - $netoLinea;
            $gravado += $netoLinea;
        }

        return ['no_gravado' => 0.0, 'exento' => 0.0, 'gravado' => $gravado, 'iva' => $ivaPorAlicuota];
    }

    // -----------------------------------------------------------------------------------
    // Filtros comunes
    // -----------------------------------------------------------------------------------

    private function aplicarFiltrosComunes(
        Builder $query,
        Request $request,
        string $idColumna,
        string $proveedorIdColumna,
        string $cuitColumna,
        string $condicionIvaIdColumna,
        ?string $tipoComprobanteColumna,
        string $nroComprobanteColumna,
    ): void {
        if ($request->filled('id')) {
            $query->where($idColumna, (int) $request->input('id'));
        }

        if ($request->filled('proveedor_id')) {
            $query->whereIn($proveedorIdColumna, (array) $request->input('proveedor_id'));
        }

        if ($request->filled('cuit')) {
            $query->where($cuitColumna, 'like', '%'.$request->input('cuit').'%');
        }

        if ($request->filled('condicion_iva_id')) {
            $query->whereIn($condicionIvaIdColumna, (array) $request->input('condicion_iva_id'));
        }

        if ($tipoComprobanteColumna !== null && $request->filled('tipo_comprobante')) {
            $query->whereIn($tipoComprobanteColumna, (array) $request->input('tipo_comprobante'));
        }

        if ($request->filled('nro_comprobante')) {
            $query->where($nroComprobanteColumna, 'like', '%'.$request->input('nro_comprobante').'%');
        }
    }
}
