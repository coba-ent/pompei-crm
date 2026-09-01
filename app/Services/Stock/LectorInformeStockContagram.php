<?php

namespace App\Services\Stock;

use App\Services\Migracion\LectorExcelContagram;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee un `Informe Stock AAAA.xlsx` exportado de Contagram (spec 094).
 *
 * Responsabilidad única: entender el formato de ese export, con sus dos trampas ya medidas.
 *
 * TRAMPA 1 — el export repite cada movimiento una vez por depósito. El mismo movimiento aparece
 * tres veces (Local, Full, Depósito Tiendanube) y sólo UNA lleva la cantidad real; las otras dos van
 * en 0 y sólo reflejan el saldo de ese depósito. Son 22.326 de las 53.844 filas de 2024-2026.
 * Se descartan acá, lo más temprano posible: cargarlas crearía 22 mil movimientos que no movieron
 * nada e inflarían el historial de cada producto, que es justo lo que la spec viene a arreglar.
 *
 * TRAMPA 2 — las fechas vienen en dos formatos en la MISMA columna, y los seriales están
 * invertidos. Medido sobre los tres archivos: los seriales tienen día <= 12 en el 100% de los casos
 * (5.474/5.474 en 2024, 6.173/6.173 en 2025, 3.582/3.582 en 2026) y los textos tienen día >= 13
 * siempre. Es la firma exacta de día/mes invertido: Excel interpretó como fecha sólo las que podía
 * leer como M/D y dejó el resto como texto. Verificación independiente: sin invertir, el archivo
 * 2026 tiene 1.163 movimientos entre septiembre y diciembre — fechas que todavía no ocurrieron.
 * Con la inversión corta en agosto, que es el mes real de la migración.
 */
class LectorInformeStockContagram
{
    /** Los datos arrancan acá: las filas 1-2 son el resumen del período y la 4 el encabezado. */
    private const FILA_ENCABEZADO = 3;

    private const COL_ID = 0;

    private const COL_FECHA = 1;

    private const COL_USUARIO = 2;

    private const COL_OPERACION = 3;

    private const COL_DESCRIPCION = 4;

    private const COL_CODIGO = 7;

    private const COL_CANTIDAD = 11;

    private const COL_DEPOSITO = 12;

    private const COL_SALDO = 13;

    public function __construct(private readonly LectorExcelContagram $lector) {}

    /**
     * @return array{filas: array<int, FilaInformeStock>, leidas: int, descartadas_cantidad_cero: int, cadena_saldos: array<int, array{cantidad: float, saldo: float|null, fila: int, operacion: string, codigo: string}>}
     */
    public function leer(string $path, int $anio, bool $conCadenaDeSaldos = false): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        // formatData: false deja las fechas como serial numérico, que es lo que necesita la
        // corrección de la TRAMPA 2 — un serial y un texto se distinguen por su tipo, no por su
        // contenido.
        $libro = $reader->load($path);
        $matriz = $libro->getActiveSheet()->toArray(null, false, false, false);

        // PhpSpreadsheet retiene el libro entero en memoria y no lo suelta al salir de scope: los
        // tres archivos juntos (53.844 filas x 14 columnas) desbordan los 512 MB por defecto. Con
        // la matriz ya extraída, el libro no hace falta.
        $libro->disconnectWorksheets();
        unset($libro);

        $filas = [];
        $leidas = 0;
        $descartadas = 0;

        // La cadena de saldos necesita TODAS las filas, incluidas las de cantidad 0: son parte de
        // la secuencia del export y salteárselas la rompería (ver VerificadorSaldosContagram).
        $cadena = [];

        for ($i = self::FILA_ENCABEZADO + 1, $n = count($matriz); $i < $n; $i++) {
            $celdas = $matriz[$i];

            if (! isset($celdas[self::COL_FECHA]) || $celdas[self::COL_FECHA] === null || $celdas[self::COL_FECHA] === '') {
                continue;
            }

            $leidas++;

            $cantidad = (float) ($celdas[self::COL_CANTIDAD] ?? 0);

            if ($conCadenaDeSaldos) {
                $cadena[] = [
                    'cantidad' => $cantidad,
                    'saldo' => isset($celdas[self::COL_SALDO]) && $celdas[self::COL_SALDO] !== ''
                        ? (float) $celdas[self::COL_SALDO]
                        : null,
                    'fila' => $i + 1,
                    'operacion' => trim((string) ($celdas[self::COL_OPERACION] ?? '')),
                    'codigo' => trim((string) ($celdas[self::COL_CODIGO] ?? '')),
                ];
            }

            if ($cantidad == 0.0) {
                $descartadas++;

                continue;
            }

            $filas[] = new FilaInformeStock(
                idOperacion: $this->idOperacion($celdas[self::COL_ID] ?? null),
                fecha: $this->fecha($celdas[self::COL_FECHA], $anio, $i + 1, $path),
                usuario: $this->lector->texto($celdas[self::COL_USUARIO] ?? null),
                operacion: trim((string) ($celdas[self::COL_OPERACION] ?? '')),
                descripcion: $this->lector->texto($celdas[self::COL_DESCRIPCION] ?? null),
                codigo: trim((string) ($celdas[self::COL_CODIGO] ?? '')),
                cantidad: $cantidad,
                deposito: trim((string) ($celdas[self::COL_DEPOSITO] ?? '')),
                saldo: isset($celdas[self::COL_SALDO]) && $celdas[self::COL_SALDO] !== ''
                    ? (float) $celdas[self::COL_SALDO]
                    : null,
                anio: $anio,
                fila: $i + 1,
            );
        }

        return [
            'filas' => $filas,
            'leidas' => $leidas,
            'descartadas_cantidad_cero' => $descartadas,
            'cadena_saldos' => $cadena,
        ];
    }

    private function idOperacion(mixed $valor): ?int
    {
        if ($valor === null || $valor === '' || $valor === '-') {
            return null;
        }

        return (int) $valor;
    }

    /**
     * Fecha con los seriales invertidos (TRAMPA 2), y con guarda de rango.
     *
     * Una fecha que cae fuera del año del archivo ABORTA la corrida en vez de cargarse. Cargar una
     * fecha mal parseada es peor que no cargar nada: queda un movimiento en el futuro que nadie va a
     * poder explicar después.
     */
    private function fecha(mixed $valor, int $anio, int $fila, string $path): CarbonImmutable
    {
        $fecha = $this->lector->fecha($valor, invertida: true);

        if ($fecha === null) {
            throw new \RuntimeException("Fecha ilegible en {$path} fila {$fila}: ".var_export($valor, true));
        }

        if ($fecha->year !== $anio) {
            throw new \RuntimeException(
                "Fecha fuera del año del archivo en {$path} fila {$fila}: {$fecha->toDateString()} no es de {$anio}. ".
                'Revisar el parseo antes de seguir.'
            );
        }

        return $fecha;
    }
}
