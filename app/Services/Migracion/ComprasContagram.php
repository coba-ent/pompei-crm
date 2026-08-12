<?php

namespace App\Services\Migracion;

use Carbon\CarbonImmutable;

/**
 * Arma los comprobantes de compra históricos (2021-2026) cruzando los dos exports de Contagram.
 *
 * Gemelo de {@see ComprobantesContagram}, con las diferencias propias del módulo de Compras:
 * la columna de fecha se llama `Fecha` (no `Emisión`), el tipo viene con el nombre completo
 * (`Compra` / `Nota de Crédito` / `Nota de Débito`) y **las fechas vienen invertidas** — Compras es
 * una de las dos carpetas afectadas por ese defecto (plan §3.1), a diferencia de Ventas.
 *
 * El importe de cada renglón sale de `Precio unitario`, que es lo que se pagó. `Costo` es otra cosa
 * (el costo de reposición del producto) y usarlo inflaría la compra: en la primera fila de 2023,
 * costo 5.173 contra precio 3.255 sobre la misma cantidad.
 */
class ComprasContagram
{
    public const ANIOS = ['2021', '2022', '2023', '2024', '2025', '2026'];

    public const CORTE = '2026-08-05';

    private const COLUMNAS_IVA = [
        '2.5' => 'IVA - 2,5%', '5' => 'IVA - 5%', '10.5' => 'IVA - 10,5%',
        '21' => 'IVA - 21%', '27' => 'IVA - 27%',
    ];

    /**
     * Conceptos que van fuera del neto gravado y suman al total (spec 061). Sin ellos, el total de
     * una nota no cierra: la NC 105 de la compra 2107 tiene $2.411,96 de percepción de IVA que el
     * PDF no muestra y que explicaba toda su diferencia.
     *
     * El nombre del concepto es el que usa el selector de la aplicación, para que una nota migrada
     * se vea igual que una cargada a mano.
     */
    private const COLUMNAS_CONCEPTO = [
        'Perc. IVA' => ['percepcion', 'IVA (Percepción)'],
        'Perc. IIBB' => ['percepcion', 'IIBB Buenos Aires'],
        'Imp. Internos' => ['impuesto_interno', 'Impuestos Internos'],
    ];

    public function __construct(
        private readonly LectorExcelContagram $lector,
        private readonly string $base,
    ) {}

    /** @return array<string, array<string, mixed>> indexado por legacy_id */
    public function delAnio(string $anio): array
    {
        $porItem = $this->lector->leer("{$this->base}/Compras/{$anio} Compras.xlsx");
        $resumen = $this->indexarResumen($anio);

        $grupos = [];
        foreach ($porItem['filas'] as $fila) {
            $id = $this->lector->texto($fila['Id'] ?? null);
            if ($id !== null) {
                $grupos[$id.'|'.$this->familia($fila)][] = $fila;
            }
        }

        $comprobantes = [];
        foreach ($grupos as $clave => $filas) {
            [$id, $familia] = explode('|', $clave);
            $c = $this->armar($anio, $id, $familia, $filas, $resumen[$id] ?? null);
            $comprobantes[$c['legacy_id']] = $c;
        }

        return $comprobantes;
    }

    /**
     * Resumen `c/ pago`, indexado por Id.
     *
     * Los nombres de archivo son inconsistentes entre años —`c_ cobro` en 2021-2024, `c_ pago` en
     * 2025-2026, y 2023 dice "Comrpas"—, así que se resuelve por patrón del año y no por nombre.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexarResumen(string $anio): array
    {
        $match = glob("{$this->base}/Compras c- pago/{$anio}*.xlsx");
        if ($match === [] || $match === false) {
            return [];
        }

        $out = [];
        foreach ($this->lector->leer($match[0])['filas'] as $fila) {
            $id = $this->lector->texto($fila['Id'] ?? null);
            if ($id !== null) {
                $out[$id] = $fila;
            }
        }

        return $out;
    }

    private function familia(array $fila): string
    {
        $tc = mb_strtolower($this->lector->texto($fila['Tipo de Comprobante'] ?? null) ?? '');

        return match (true) {
            str_contains($tc, 'crédito') || str_contains($tc, 'credito') => 'NC',
            str_contains($tc, 'débito') || str_contains($tc, 'debito') => 'ND',
            default => 'FC',
        };
    }

    private function armar(string $anio, string $id, string $familia, array $filas, ?array $resumen): array
    {
        $L = $this->lector;
        $cab = $resumen ?? $filas[0];

        // Igual que en ventas: el `Total Compra` se repite por fila cuando es dato de cabecera, y
        // se suma cuando viene por renglón.
        $totales = array_unique(array_map(
            fn ($f) => round((float) ($L->numero($f['Total Compra'] ?? null) ?? 0), 2), $filas
        ));
        $total = count($totales) === 1 ? reset($totales) : array_sum($totales);

        // El `c/ pago` manda para las facturas; las NC/ND no figuran ahí con su propio total.
        $delResumen = $resumen !== null ? $L->numero($cab['Total Compra'] ?? null) : null;
        if ($familia === 'FC' && $delResumen !== null && abs($delResumen) > 0.005) {
            $total = $delResumen;
        }

        $items = array_map(fn ($f) => $this->armarItem($f), $filas);
        $items = $this->reconstruirIvaSiFalta($items, (float) $total);

        $subCon = $L->numero($cab['Subtotal con Descuento'] ?? null);
        if ($subCon === null || abs($subCon) < 0.005) {
            $subCon = round(array_sum(array_column($items, 'subtotal')), 2);
        }

        // `invertida: true` — Compras arrastra el defecto de día/mes cambiados (§3.1).
        $fecha = $L->fecha($cab['Emisión'] ?? $cab['Fecha'] ?? null, true);

        return [
            // Prefijo `COMPRA-` obligatorio: las notas de crédito/débito de compras y de ventas
            // comparten la tabla `notas_credito_debito`, y el Id de Contagram arranca de 1 en cada
            // módulo. Sin el prefijo, la NC 1 de compras de 2021 colisiona con la NC 1 de ventas
            // de 2021 y el importador la saltea como "ya existente": se perdían 18 notas en
            // silencio (medido el 10/08/2026). El número sigue siendo el último segmento, así que
            // la búsqueda por número de Contagram no cambia.
            'legacy_id' => "COMPRA-{$anio}-{$familia}-{$id}",
            'anio' => $anio,
            'id_excel' => $id,
            'familia' => $familia,
            'tiene_resumen' => $resumen !== null,

            'fecha_emision' => $fecha,
            'fecha_vto_pago' => $L->fecha($cab['Vencimiento'] ?? null, true),
            'servicio_desde' => $L->fecha($cab['Servicio Desde'] ?? null, true),
            'servicio_hasta' => $L->fecha($cab['Servicio Hasta'] ?? null, true),

            'proveedor' => $L->normalizarNombre($cab['Proveedor'] ?? ''),
            'cuit' => $L->texto($cab['CUIT'] ?? $cab['CUIT / DNI'] ?? null),
            'categoria' => $L->texto($cab['Categoría'] ?? null),

            'tipo' => strtoupper($L->texto($cab['Tipo'] ?? null) ?? '') ?: null,
            'punto_venta' => $L->numero($cab['Punto de Venta'] ?? null),
            'nro_factura' => $L->numero($cab['N° de Factura'] ?? $cab['N° Factura'] ?? null),

            'subtotal_sin_descuento' => round((float) ($L->numero($cab['Subtotal sin Descuento'] ?? null) ?? $subCon), 2),
            'descuento' => round((float) ($L->numero($cab['Descuento en $'] ?? null) ?? 0), 2),
            'subtotal_con_descuento' => round((float) $subCon, 2),
            'total' => round((float) $total, 2),

            'pagado' => round((float) ($L->numero($cab['Pagado'] ?? null) ?? 0), 2),
            'estado' => $L->texto($cab['Estado'] ?? null),
            'nota_interna' => $L->texto($cab['Nota Interna'] ?? null),
            'medios_pago' => array_values(array_filter(array_map(
                'trim', explode(' - ', $L->texto($cab['Medio de Pago'] ?? null) ?? '')
            ))),

            'conceptos' => $this->armarConceptos($cab, $items, (float) $total),

            // `Total NC`/`Total ND` sólo existen en el resumen `c/ pago`, y son la única pista del
            // export sobre qué notas ajustan esta compra: no dice cuáles, pero sí cuánto suman.
            // Con eso se reconstruye el vínculo que Contagram no exporta (ver §8d del registro).
            'total_nc' => abs((float) ($L->numero($cab['Total NC'] ?? null) ?? 0)),
            'total_nd' => abs((float) ($L->numero($cab['Total ND'] ?? null) ?? 0)),

            'items' => $items,
        ];
    }

    /**
     * Percepciones e impuestos internos de la cabecera, en el formato que guarda la aplicación
     * en `notas_credito_debito.impuestos` / `compras.impuestos`.
     *
     * @return array<int, array{tipo:string, concepto:string, monto:float}>
     */
    private function armarConceptos(array $cab, array $items, float $total): array
    {
        $conceptos = [];

        foreach (self::COLUMNAS_CONCEPTO as $columna => [$tipo, $nombre]) {
            $monto = abs((float) ($this->lector->numero($cab[$columna] ?? null) ?? 0));

            if ($monto > 0.005) {
                $conceptos[] = ['tipo' => $tipo, 'concepto' => $nombre, 'monto' => round($monto, 2)];
            }
        }

        // El export **no desglosa la percepción de IVA**: la suma dentro de `Total Compra` pero deja
        // su columna vacía. Se ve claro en la NC 105 de la compra 2107 — items+IVA dan 35.523,79
        // contra un total de 37.935,75— y al abrir esa nota en Contagram el hueco es exactamente
        // una percepción de IVA de $2.411,96.
        //
        // Sin esto el desglose de 56 notas no cierra contra su propio monto. Es una deducción, no
        // un dato del archivo: se asume percepción de IVA porque es lo que se pudo verificar, y
        // sólo cuando el residuo es positivo (un residuo negativo sería otra cosa y se deja pasar).
        $sumaItems = array_sum(array_column($items, 'subtotal_con_iva'));
        $residuo = round(abs($total) - abs($sumaItems) - array_sum(array_column($conceptos, 'monto')), 2);

        if ($residuo > 0.05) {
            $conceptos[] = ['tipo' => 'percepcion', 'concepto' => 'IVA (Percepción)', 'monto' => $residuo];
        }

        return $conceptos;
    }

    private function armarItem(array $f): array
    {
        $L = $this->lector;

        $cantidad = (float) ($L->numero($f['Cantidad'] ?? null) ?? 0);
        // `Precio unitario` (u minúscula) es lo pagado; `Costo` es el costo de reposición.
        $precio = (float) ($L->numero($f['Precio unitario'] ?? null) ?? 0);

        $subtotal = $L->numero($f['Subtotal con Descuento'] ?? null);
        if ($subtotal === null || abs($subtotal) < 0.005) {
            $subtotal = $cantidad * $precio;
        }

        $iva = 0.0;
        $ivaPct = null;
        foreach (self::COLUMNAS_IVA as $pct => $col) {
            $monto = (float) ($L->numero($f[$col] ?? null) ?? 0);
            if (abs($monto) > 0.005) {
                $iva += $monto;
                $ivaPct = $pct;
            }
        }

        return [
            'producto_legacy_id' => $L->idProductoDesdeCodigo($f['Código'] ?? null),
            'codigo' => $L->texto($f['Código'] ?? null),
            'descripcion' => $L->texto($f['Producto/Servicio'] ?? null) ?? 'Sin descripción',
            'rubro' => $L->texto($f['Tipo.1'] ?? null),
            'costo' => $L->numero($f['Costo'] ?? null),
            'cantidad' => $cantidad,
            'precio_unitario' => round($precio, 2),
            'iva_pct' => $ivaPct,
            'subtotal' => round((float) $subtotal, 2),
            'subtotal_con_iva' => round((float) $subtotal + $iva, 2),
        ];
    }

    /** Ver ComprobantesContagram::reconstruirIvaSiFalta — mismo criterio y mismo cortafuegos. */
    private function reconstruirIvaSiFalta(array $items, float $total): array
    {
        $neto = round(array_sum(array_column($items, 'subtotal')), 2);
        $conIva = round(array_sum(array_column($items, 'subtotal_con_iva')), 2);

        if (abs($total) < 0.005 || abs($neto) < 0.005 || abs($conIva - $neto) > 0.005) {
            return $items;
        }

        $factor = $total / $neto;
        if ($factor <= 1.0 || $factor > 2.0) {
            return $items;
        }

        foreach ($items as $i => $item) {
            $items[$i]['subtotal_con_iva'] = round($item['subtotal'] * $factor, 2);
            $items[$i]['iva_pct'] ??= (string) round(($factor - 1) * 100, 1);
        }

        return $items;
    }

    public function dentroDelCorte(?CarbonImmutable $fecha): bool
    {
        return $fecha !== null && $fecha->format('Y-m-d') <= self::CORTE;
    }
}
