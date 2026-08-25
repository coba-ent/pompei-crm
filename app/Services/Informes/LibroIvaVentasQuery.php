<?php

namespace App\Services\Informes;

use App\Models\Venta;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Libro IVA Ventas (spec 077, US1/US3): una fila por comprobante (venta o NC/ND de venta) del
 * período elegido, con desglose impositivo completo. Ver {@see LibroIvaQuery} sobre la técnica de
 * agregación por comprobante (subqueries correlacionadas en vez de `GROUP BY`).
 */
class LibroIvaVentasQuery extends LibroIvaQuery
{
    /** Alícuotas con columna propia, en el orden del contrato (columnas 11-15). */
    private const ALICUOTAS = ['2.5', '5', '10.5', '21', '27'];

    public function __construct(private DesgloseImpositivoVenta $desglose) {}

    public function detalle(Request $request): Builder
    {
        $items = $this->queryVentas($request);
        $notas = $this->queryNotas($request);

        return $notas === null ? $items : $items->unionAll($notas);
    }

    // -----------------------------------------------------------------------------------
    // Rama de ventas
    // -----------------------------------------------------------------------------------

    private function queryVentas(Request $request): Builder
    {
        $d = $this->desglose;
        $neto = 'vi.subtotal';
        $ivaPct = 'vi.iva_pct';
        $conIva = 'vi.subtotal_con_iva';

        $subVi = fn (string $expr) => "COALESCE((SELECT SUM({$expr}) FROM venta_items vi WHERE vi.venta_id = ventas.id), 0)";

        $nroComprobante = "COALESCE((SELECT cf.numero FROM comprobantes_fiscales cf ".
            'WHERE cf.comprobantable_id = ventas.id AND cf.comprobantable_type = '.ExpresionSql::literal(Venta::class).
            " AND cf.estado = 'aprobado' AND cf.deleted_at IS NULL ORDER BY cf.id DESC LIMIT 1), ventas.nro_comprobante)";

        $query = DB::table('ventas')
            ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->leftJoin('condiciones_iva', 'condiciones_iva.id', '=', 'clientes.condicion_iva_id')
            ->whereNull('ventas.deleted_at')
            ->selectRaw(implode(', ', array_merge([
                'ventas.id as id',
                'ventas.fecha_emision as emision',
                'ventas.tipo_comprobante as tipo',
                "{$nroComprobante} as nro_comprobante",
                "COALESCE(clientes.nombre, 'Sin cliente') as contraparte",
                'clientes.cuit as cuit',
                "COALESCE(condiciones_iva.nombre, '') as condicion_iva",
                $subVi($d->sqlNeto('no_gravado', $neto, $ivaPct)).' as neto_no_gravado',
                $subVi($d->sqlNeto('exento', $neto, $ivaPct)).' as neto_exento',
                $subVi($d->sqlNeto('gravado', $neto, $ivaPct)).' as neto_gravado',
            ], array_map(
                fn (string $a) => $subVi($d->sqlIva($a, $neto, $ivaPct, $conIva)).' as iva_'.str_replace('.', '_', $a),
                self::ALICUOTAS
            ), [
                $this->sqlPercIva('venta_conceptos', 'venta_id', 'ventas.id').' as perc_iva',
                $this->sqlPercIibb('venta_conceptos', 'venta_id', 'ventas.id').' as perc_iibb',
                $this->sqlImpuestosInternos('venta_conceptos', 'venta_id', 'ventas.id').' as imp_internos',
                '0 as imp_municipales',
            ])));

        $this->filtrarPeriodo($query, $request, 'ventas.fecha_emision');
        $this->aplicarFiltrosComunes($query, $request, 'ventas.id', 'ventas.cliente_id', 'clientes.cuit', 'clientes.condicion_iva_id', 'ventas.tipo_comprobante', 'ventas.nro_comprobante');
        $this->filtrarMedioTesoreria($query, $request, 'cobros', 'venta_id', 'ventas.id');
        $this->filtrarProvincia($query, $request, 'clientes');
        $this->filtrarArcaManuales($query, $request);

        return $query;
    }

    /** T043: EXISTS sobre `comprobantes_fiscales` con `estado = 'aprobado'` — nunca el `morphOne` (data-model §3, incidente Venta 24447). */
    private function sqlFirme(): string
    {
        return "EXISTS (SELECT 1 FROM comprobantes_fiscales cf WHERE cf.comprobantable_id = ventas.id ".
            'AND cf.comprobantable_type = '.ExpresionSql::literal(Venta::class).
            " AND cf.estado = 'aprobado' AND cf.deleted_at IS NULL)";
    }

    /** FR-014/016/017/018/019: casillas ARCA (default tildada) / Manuales (default destildada). */
    private function filtrarArcaManuales(Builder $query, Request $request): void
    {
        $arca = $request->has('arca') ? filter_var($request->input('arca'), FILTER_VALIDATE_BOOLEAN) : true;
        $manuales = $request->has('manuales') ? filter_var($request->input('manuales'), FILTER_VALIDATE_BOOLEAN) : false;

        if ($arca && $manuales) {
            return; // universo completo, sin condición.
        }

        if (! $arca && ! $manuales) {
            $query->whereRaw('1 = 0'); // FR-019: vacío, sin error.

            return;
        }

        $firme = $this->sqlFirme();
        $arca ? $query->whereRaw($firme) : $query->whereRaw('NOT '.$firme);
    }

    // -----------------------------------------------------------------------------------
    // Rama de NC/ND de venta
    // -----------------------------------------------------------------------------------

    /**
     * Las NC/ND se materializan en PHP (una consulta a `venta_items` por nota para resolver sus
     * alícuotas heredadas) y se agregan a la unión como filas SQL literales — no hay forma
     * portable de expresar las 4 ramas de precedencia de FR-022d (impuestos propios del JSON /
     * alícuota heredada / prorrateo / No Gravado) como una única expresión SQL sin `JSON_TABLE`,
     * que MySQL 8 y SQLite no comparten. El volumen de NC/ND de un período es órdenes de magnitud
     * menor que el de comprobantes, así que este costo es aceptable y sigue corriendo una sola vez
     * por request, no por fila del detalle paginado.
     */
    private function queryNotas(Request $request): ?Builder
    {
        [$desde, $hasta] = $this->rangoPeriodo($request);

        $query = DB::table('notas_credito_debito')
            ->join('ventas', 'ventas.id', '=', 'notas_credito_debito.venta_id')
            ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->leftJoin('condiciones_iva', 'condiciones_iva.id', '=', 'clientes.condicion_iva_id')
            ->whereNull('notas_credito_debito.deleted_at')
            ->whereNull('ventas.deleted_at')
            ->whereNotNull('notas_credito_debito.venta_id')
            ->whereDate('notas_credito_debito.mes_imputacion', '>=', $desde)
            ->whereDate('notas_credito_debito.mes_imputacion', '<=', $hasta);

        $this->aplicarFiltrosComunes($query, $request, 'notas_credito_debito.id', 'ventas.cliente_id', 'clientes.cuit', 'clientes.condicion_iva_id', null, 'notas_credito_debito.nro_comprobante');
        $this->filtrarMedioTesoreria($query, $request, 'cobros', 'venta_id', 'ventas.id');
        $this->filtrarProvincia($query, $request, 'clientes');

        $notas = $query->select([
            'notas_credito_debito.id', 'notas_credito_debito.venta_id', 'notas_credito_debito.tipo',
            'notas_credito_debito.tipo_comprobante', 'notas_credito_debito.monto', 'notas_credito_debito.impuestos',
            'notas_credito_debito.fecha_emision', 'notas_credito_debito.nro_comprobante',
            'clientes.nombre as cliente_nombre', 'clientes.cuit', 'condiciones_iva.nombre as condicion_iva_nombre',
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

    /** Fila SQL literal de una NC/ND, con las 19 columnas del contrato (data-model §4, FR-022d). */
    private function filaLiteralNota($nota): string
    {
        $signo = $nota->tipo === 'credito' ? -1 : 1;
        $monto = (float) $nota->monto;

        [$percIva, $percIibb, $impInternos] = $this->desgloseConceptosNota($nota);
        $desglose = $this->desgloseNota($nota, $monto);

        $tipo = $this->tipoCompuesto($nota);
        $contraparte = $nota->cliente_nombre ?? 'Sin cliente';
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

    /** Percepciones/impuestos internos de la nota, desde su JSON `impuestos` (data-model §4). */
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

    /**
     * Desglose de netos/IVA de la nota — las 4 ramas de precedencia de FR-022d/data-model §4:
     * 1) entradas de IVA propias en el JSON `impuestos`; 2) alícuota heredada del comprobante
     * ajustado si es única; 3) prorrateo entre varias alícuotas; 4) No Gravado si no hay
     * comprobante ajustado identificable.
     *
     * @return array{no_gravado: float, exento: float, gravado: float, iva: array<string, float>}
     */
    private function desgloseNota($nota, float $monto): array
    {
        $ivaPorAlicuota = array_fill_keys(self::ALICUOTAS, 0.0);

        // Rama 1: entradas de IVA propias del JSON `impuestos` — formato {tipo:'iva', alicuota, neto}.
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

        // Ramas 2-4: alícuotas del comprobante (venta) ajustado.
        $gravadoPorAlicuota = DB::table('venta_items')
            ->where('venta_id', $nota->venta_id)
            ->whereIn('iva_pct', self::ALICUOTAS)
            ->groupBy('iva_pct')
            ->selectRaw('iva_pct, SUM(subtotal) as neto')
            ->pluck('neto', 'iva_pct')
            ->map(fn ($v) => (float) $v)
            ->all();

        if ($gravadoPorAlicuota === []) {
            // Rama 4: sin comprobante ajustado identificable con alícuota — todo a No Gravado.
            return ['no_gravado' => $monto, 'exento' => 0.0, 'gravado' => 0.0, 'iva' => $ivaPorAlicuota];
        }

        if (count($gravadoPorAlicuota) === 1) {
            // Rama 2: alícuota única heredada — neto = monto / (1 + a), iva = monto - neto.
            $alicuota = array_key_first($gravadoPorAlicuota);
            $neto = $monto / (1 + ((float) $alicuota) / 100);
            $ivaPorAlicuota[$alicuota] = $monto - $neto;

            return ['no_gravado' => 0.0, 'exento' => 0.0, 'gravado' => $neto, 'iva' => $ivaPorAlicuota];
        }

        // Rama 3: varias alícuotas — el monto se reparte proporcional al neto de cada una.
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
    // Filtros comunes (comprobante + nota)
    // -----------------------------------------------------------------------------------

    private function aplicarFiltrosComunes(
        Builder $query,
        Request $request,
        string $idColumna,
        string $clienteIdColumna,
        string $cuitColumna,
        string $condicionIvaIdColumna,
        ?string $tipoComprobanteColumna,
        string $nroComprobanteColumna,
    ): void {
        if ($request->filled('id')) {
            $query->where($idColumna, (int) $request->input('id'));
        }

        if ($request->filled('cliente_id')) {
            $query->whereIn($clienteIdColumna, (array) $request->input('cliente_id'));
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
