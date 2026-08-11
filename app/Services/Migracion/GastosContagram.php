<?php

namespace App\Services\Migracion;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee los gastos históricos de Contagram, que vienen en **dos formatos distintos**.
 *
 * - **2025-2026: plano.** Una fila por gasto, con encabezado en la fila 1 que arranca con `Estado`
 *   y trae su propia columna `Categoría`.
 * - **2021-2024: informe agrupado.** No hay una tabla, hay un listado por categoría: una fila
 *   suelta con el nombre de la categoría, otra con la subcategoría, un encabezado
 *   `Id | Fecha | Subcategoría | ...`, las filas de gasto, y un `Total <categoría>:` al cerrar.
 *   **No traen columna `Categoría`**: hay que arrastrarla de la última fila separadora.
 *
 * Y no se puede deducir la categoría por el nombre de la subcategoría: **6 nombres existen bajo más
 * de un padre** (`Otros`, `ABL`, `Alquiler`, `Aysa`, `Edenor`, `Personal Flow` — estos cinco bajo
 * *Juan Personal* y *Oficina Pompei* a la vez), lo que afectaría a 607 gastos. Por eso el arrastre
 * de la fila separadora no es una comodidad sino la única fuente correcta.
 *
 * Verificado: los dos parsers juntos detectan 9.394 filas de gasto, que es exactamente el total
 * de control del plan §6.
 */
class GastosContagram
{
    public const ANIOS = ['2021', '2022', '2023', '2024', '2025', '2026'];

    public const CORTE = '2026-08-05';

    public function __construct(
        private readonly LectorExcelContagram $lector,
        private readonly string $base,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function delAnio(string $anio): array
    {
        $path = "{$this->base}/Gastos/{$anio} Gastos.xlsx";
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $matriz = $reader->load($path)->getActiveSheet()->toArray(null, false, false, false);

        return trim((string) ($matriz[0][0] ?? '')) === 'Estado'
            ? $this->leerPlano($matriz, $anio)
            : $this->leerAgrupado($matriz, $anio);
    }

    /** Formato 2025-2026: tabla común con encabezado en la fila 1. */
    private function leerPlano(array $matriz, string $anio): array
    {
        $header = array_map(fn ($c) => trim((string) ($c ?? '')), $matriz[0]);
        $col = array_flip($header);
        $gastos = [];

        foreach (array_slice($matriz, 1) as $fila) {
            $id = trim((string) ($fila[$col['Id']] ?? ''));
            if ($id === '' || ! is_numeric($id)) {
                continue;
            }

            $gastos[] = $this->armar($anio, $id,
                $fila[$col['Emisión'] ?? $col['Fecha']] ?? null,
                $fila[$col['Categoría']] ?? null,
                $fila[$col['Subcategoría']] ?? null,
                $fila[$col['Descripción']] ?? null,
                $fila[$col['Medio de pago']] ?? null,
                // El importe se llama `Monto` en el formato plano y `Total` en el agrupado.
                $fila[$col['Monto'] ?? $col['Total'] ?? -1] ?? null,
                $fila[$col['Estado']] ?? null,
            );
        }

        return $gastos;
    }

    /** Formato 2021-2024: informe agrupado; la categoría se arrastra de la fila separadora. */
    private function leerAgrupado(array $matriz, string $anio): array
    {
        $gastos = [];
        $categoria = null;
        $col = null;
        $separadoras = [];

        foreach ($matriz as $fila) {
            $c = array_map(fn ($x) => trim((string) ($x ?? '')), $fila);
            $primera = $c[0] ?? '';

            if ($primera === 'Id') {
                $col = array_flip($c);

                // Las filas separadoras acumuladas desde el bloque anterior definen la jerarquía:
                //
                //     Empleados     <- categoría
                //     Aguinaldo     <- subcategoría
                //     Id | Fecha…   <- header
                //
                // Dos seguidas significan que empezó una categoría nueva; **una sola es sólo otra
                // subcategoría de la categoría vigente** (después de "Total Aguinaldo:" viene
                // "Comisiones", que sigue siendo de Empleados). Tomar siempre la última mandaba
                // 559 gastos a una categoría raíz inventada con el nombre de su subcategoría.
                if (count($separadoras) >= 2) {
                    $categoria = $separadoras[0];
                }
                $separadoras = [];

                continue;
            }

            // Fila de datos: la primera celda es el Id numérico del gasto.
            if ($primera !== '' && is_numeric($primera) && $col !== null) {
                $gastos[] = $this->armar($anio, $primera,
                    $fila[$col['Fecha']] ?? null,
                    $categoria,
                    $fila[$col['Subcategoría']] ?? null,
                    $fila[$col['Descripción']] ?? null,
                    $fila[$col['Medio de pago']] ?? null,
                    $fila[$col['Total']] ?? null,
                    null,
                );

                continue;
            }

            // Fila separadora: un nombre solo, sin nada en el resto de las columnas. Se descartan
            // los totales de cierre y el bloque de resumen del principio.
            $restoVacio = implode('', array_slice($c, 1)) === '';
            if ($primera !== '' && $restoVacio && ! str_starts_with($primera, 'Total')
                && ! in_array($primera, ['Desde', 'Hasta', 'Gasto Total'], true)) {
                $separadoras[] = $primera;
            }
        }

        return $gastos;
    }

    private function armar(
        string $anio, string $id, mixed $fecha, ?string $categoria, ?string $subcategoria,
        ?string $descripcion, ?string $medioPago, mixed $total, ?string $estado,
    ): array {
        return [
            'legacy_id' => "GASTO-{$anio}-{$id}",
            'anio' => $anio,
            'id_excel' => $id,
            // `invertida: false`: Gastos no arrastra el defecto de día/mes de Cuentas/ y Compras/.
            'fecha' => $this->lector->fecha($fecha),
            'categoria' => $this->lector->texto($categoria),
            'subcategoria' => $this->lector->texto($subcategoria),
            'descripcion' => $this->lector->texto($descripcion),
            'medio_pago' => $this->lector->texto($medioPago),
            'monto' => round((float) ($this->lector->numero($total) ?? 0), 2),
            'pendiente' => $estado !== null && mb_strtolower($estado) !== 'pagado',
        ];
    }

    public function dentroDelCorte(?CarbonImmutable $fecha): bool
    {
        return $fecha !== null && $fecha->format('Y-m-d') <= self::CORTE;
    }
}
