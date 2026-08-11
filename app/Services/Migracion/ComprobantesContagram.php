<?php

namespace App\Services\Migracion;

use Carbon\CarbonImmutable;

/**
 * Arma los comprobantes de venta históricos (2021-2026) cruzando los dos exports de Contagram.
 *
 * Separado del comando a propósito: la lectura y normalización es la parte que hay que poder
 * ejercitar sin tocar la base (reportes, dry-run, tests), y el comando queda sólo con la escritura.
 *
 * Reglas implementadas — todas medidas, con su evidencia en
 * docs/importacion_2021_2026_plan_tecnico.md §3:
 *
 * - El **total de cabecera sale del `c/ cobro`** y los **ítems del por-ítem** (§3.9). Arbitrado
 *   contra la suma real de los ítems: el `c/ cobro` acierta 210 a 14.
 * - La clave de agrupación es **`Id` + familia** (§3.11): en 2021 el mismo Id se reusa entre
 *   facturas y notas de crédito.
 * - `Tipo de Comprobante` vacío **es una factura** (§3.11), no basura: son las ventas sin
 *   comprobante fiscal (coincide exactamente con `ARCA = ---`).
 * - En 2021 los importes de cabecera del por-ítem vienen vacíos (§3.10), así que el importe de cada
 *   ítem se calcula como `Cantidad × Precio Unitario` cuando `Subtotal con Descuento` no está.
 */
class ComprobantesContagram
{
    public const ANIOS = ['2021', '2022', '2023', '2024', '2025', '2026'];

    /** El corte del import: hasta acá operaban en Contagram; después, en el CRM. */
    public const CORTE = '2026-08-05';

    /** Alícuotas de IVA con su columna en el Excel. Sólo 21% y 10,5% tienen uso real. */
    private const COLUMNAS_IVA = [
        '2.5' => 'IVA - 2,5%', '5' => 'IVA - 5%', '10.5' => 'IVA - 10,5%',
        '21' => 'IVA - 21%', '27' => 'IVA - 27%',
    ];

    public function __construct(
        private readonly LectorExcelContagram $lector,
        private readonly string $base,
    ) {}

    /**
     * Comprobantes de un año, indexados por `legacy_id`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function delAnio(string $anio, ?array $headerCanonico = null): array
    {
        $porItem = $this->lector->leer("{$this->base}/Ventas/Ventas {$anio}.xlsx", $headerCanonico);
        $resumen = $this->indexarResumen($anio);

        $grupos = [];
        foreach ($porItem['filas'] as $fila) {
            $id = $this->lector->texto($fila['Id'] ?? null);
            if ($id === null) {
                continue;
            }
            $grupos[$id.'|'.$this->familia($fila)][] = $fila;
        }

        $comprobantes = [];
        foreach ($grupos as $clave => $filas) {
            [$id, $familia] = explode('|', $clave);
            $c = $this->armar($anio, $id, $familia, $filas, $resumen[$id.'|'.$familia] ?? null);
            $comprobantes[$c['legacy_id']] = $c;
        }

        // La 24267 sólo existe en el `c/ cobro` (fue borrada en Contagram). No se inventa: se
        // reporta como faltante y el comando decide. Ver §3.11.
        return $comprobantes;
    }

    /** Ids presentes en el `c/ cobro` que no tienen ítems — deberían ser sólo la 24267. */
    public function sinItems(string $anio, array $comprobantes): array
    {
        $faltan = [];
        foreach ($this->indexarResumen($anio) as $clave => $fila) {
            [$id, $familia] = explode('|', $clave);
            if (! isset($comprobantes["{$anio}-{$familia}-{$id}"])) {
                $faltan[$id] = $this->lector->numero($fila['Total Venta'] ?? null) ?? 0.0;
            }
        }

        return $faltan;
    }

    /** @return array<string, array<string, mixed>> clave `Id|familia` */
    private function indexarResumen(string $anio): array
    {
        $r = $this->lector->leer("{$this->base}/Ventas c- cobro/{$anio} Ventas c_ cobro.xlsx");

        $out = [];
        foreach ($r['filas'] as $fila) {
            $id = $this->lector->texto($fila['Id'] ?? null);
            if ($id !== null) {
                $out[$id.'|'.$this->familia($fila)] = $fila;
            }
        }

        return $out;
    }

    /**
     * Familia del comprobante. `FC` es el default deliberado: cubre FC/FCA/FCB/FCC **y** las filas
     * sin `Tipo de Comprobante`, que son ventas reales sin comprobante fiscal (§3.11).
     */
    private function familia(array $fila): string
    {
        $tc = strtoupper($this->lector->texto($fila['Tipo de Comprobante'] ?? null) ?? '');

        return match (true) {
            str_starts_with($tc, 'NC') => 'NC',
            str_starts_with($tc, 'ND') => 'ND',
            default => 'FC',
        };
    }

    private function armar(string $anio, string $id, string $familia, array $filas, ?array $resumen): array
    {
        $L = $this->lector;
        $primera = $filas[0];
        // La cabecera es consistente dentro del grupo en los 6 años (verificado, §3.11), así que
        // alcanza con la primera fila para los campos que el `c/ cobro` no trae.
        $cab = $resumen ?? $primera;

        // Total según el por-ítem. El `Total Venta` normalmente se **repite** idéntico en todas las
        // filas del comprobante (es un dato de cabecera desnormalizado), pero cuando difiere está
        // expresado por renglón y hay que sumarlo. Distinguir los dos casos por `array_unique` no
        // es un detalle: tomando siempre la primera fila, las notas de crédito multi-renglón
        // quedaban en $43,0M en vez de $56,2M.
        $totales = array_unique(array_map(
            fn ($f) => round((float) ($L->numero($f['Total Venta'] ?? null) ?? 0), 2), $filas
        ));
        $total = count($totales) === 1 ? reset($totales) : array_sum($totales);

        // Para las facturas manda el `c/ cobro` (§3.9). Las NC/ND no figuran ahí —su `Total Venta`
        // en ese export es el de la venta original—, así que conservan el del por-ítem.
        $delResumen = $resumen !== null ? $L->numero($cab['Total Venta'] ?? null) : null;
        if ($familia === 'FC' && $delResumen !== null && abs($delResumen) > 0.005) {
            $total = $delResumen;
        }

        $items = array_map(fn ($f) => $this->armarItem($f), $filas);

        // Si la cabecera no trae subtotales (2021), se reconstruyen desde los ítems: sin esto la
        // venta quedaría con total correcto pero subtotal en 0, y no cerraría contra sus renglones.
        $subSin = $L->numero($cab['Subtotal Sin Descuento'] ?? $cab['Subtotal sin Descuento'] ?? null);
        $subCon = $L->numero($cab['Subtotal con Descuento'] ?? null);
        if ($subCon === null || abs($subCon) < 0.005) {
            $subCon = round(array_sum(array_column($items, 'subtotal')), 2);
            $subSin ??= $subCon;
        }

        $items = $this->reconstruirIvaSiFalta($items, (float) $total);

        return [
            'legacy_id' => "{$anio}-{$familia}-{$id}",
            'anio' => $anio,
            'id_excel' => $id,
            'familia' => $familia,
            'tiene_resumen' => $resumen !== null,

            'fecha_emision' => $L->fecha($cab['Emisión'] ?? null),
            'fecha_vto_cobro' => $L->fecha($cab['Vencimiento'] ?? null),
            'servicio_desde' => $L->fecha($cab['Servicio Desde'] ?? null),
            'servicio_hasta' => $L->fecha($cab['Servicio Hasta'] ?? null),

            'cliente' => $L->normalizarNombre($cab['Cliente'] ?? ''),
            'cuit' => $L->texto($cab['CUIT'] ?? $cab['CUIT / DNI'] ?? null),
            'categoria' => $L->texto($cab['Categoría'] ?? null),
            'vendedor' => $L->texto($cab['Vendedor'] ?? null),
            'lista_precio' => $L->normalizarNombre($cab['Lista de Precios'] ?? '') ?: null,
            'deposito' => $L->texto($cab['Depósito'] ?? null),

            'tipo' => strtoupper($L->texto($cab['Tipo'] ?? null) ?? '') ?: null,
            'arca' => $L->texto($cab['ARCA'] ?? null),
            'punto_venta' => $L->numero($cab['Punto de Venta'] ?? null),
            'nro_factura' => $L->numero($cab['N° de Factura'] ?? $cab['N° Factura'] ?? null),

            'subtotal_sin_descuento' => round((float) ($subSin ?? $subCon), 2),
            'descuento' => round((float) ($L->numero($cab['Descuento en $'] ?? null) ?? 0), 2),
            'subtotal_con_descuento' => round((float) $subCon, 2),
            'total' => round((float) $total, 2),

            'cobrado' => round((float) ($L->numero($cab['Cobrado'] ?? null) ?? 0), 2),
            'estado' => $L->texto($cab['Estado'] ?? null),
            // Contagram concatena un medio por cada cobro parcial ("Visa - Visa - Caja del Local").
            'medios_cobro' => array_values(array_filter(array_map(
                'trim', explode(' - ', $L->texto($cab['Medio de Cobro'] ?? null) ?? '')
            ))),
            'nota_cliente' => $L->texto($cab['Nota Cliente'] ?? $cab['Nota para el Cliente'] ?? null),
            'nota_interna' => $L->texto($cab['Nota Interna'] ?? null),

            'items' => $items,
        ];
    }

    /**
     * Reparte el IVA entre los renglones cuando el export no lo trae desglosado.
     *
     * `Ventas 2021.xlsx` no pobla ninguna columna de IVA (§3.10), así que los ítems suman el neto
     * mientras el total de cabecera viene con IVA: la venta cerraba ~21% corta y aparecían 1.084
     * comprobantes descuadrados. Se prorratea el total real sobre el neto de cada renglón.
     *
     * Sólo actúa si **ningún** ítem trae IVA propio y hay un total contra el cual prorratear: si el
     * export sí lo desglosa, ese dato manda y esto no se mete. El tope de factor 2 es un cortafuegos
     * contra prorratear sobre un neto basura (evita inflar un renglón hasta un número absurdo).
     */
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

    private function armarItem(array $f): array
    {
        $L = $this->lector;

        $cantidad = (float) ($L->numero($f['Cantidad'] ?? null) ?? 0);
        $precio = (float) ($L->numero($f['Precio Unitario'] ?? null) ?? 0);

        // `Subtotal con Descuento` está vacío en 1.354 ventas de 2021 (§3.10): ahí manda cant × precio.
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
            'proveedor' => $L->texto($f['Proveedor'] ?? null),
            'costo' => $L->numero($f['Costo Total Actual'] ?? null),
            'cantidad' => $cantidad,
            'precio_unitario' => round($precio, 2),
            'iva_pct' => $ivaPct,
            'subtotal' => round((float) $subtotal, 2),
            'subtotal_con_iva' => round((float) $subtotal + $iva, 2),
        ];
    }

    /**
     * El corte es **inclusive**: el 05/08/2026 se importa.
     *
     * Compara la fecha calendario como texto y no dos instantes: `CarbonImmutable::parse()` resuelve
     * en la timezone de la app, y esa diferencia bastaba para que los 28 comprobantes del propio
     * 05/08 quedaran adentro o afuera según desde dónde se invocara. Como `Y-m-d` ordena
     * lexicográficamente igual que cronológicamente, la comparación de strings no tiene ese problema.
     */
    public function dentroDelCorte(?CarbonImmutable $fecha): bool
    {
        return $fecha !== null && $fecha->format('Y-m-d') <= self::CORTE;
    }
}
