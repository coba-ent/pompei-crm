<?php

namespace App\Services\Informes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Base del dataset que alimenta el motor de tablas dinámicas (spec 069).
 *
 * Entrega **una fila por ítem** —el mismo conjunto que ya produce el detalle del informe, con sus
 * mismos filtros (FR-040)— proyectando sólo las columnas que participan del cruce. El cruce en sí
 * lo arma PivotTable.js en el cliente; acá sólo se prepara la materia prima.
 *
 * ## Por qué hay un tope de filas
 *
 * El cruce se calcula en el navegador, así que el dataset entero viaja al cliente. Sin tope, un
 * "todo el año" sobre esta base manda cientos de miles de filas y cuelga la pestaña. Se corta en
 * {@see self::TOPE_FILAS} con un mensaje que invita a acotar, en vez de dejar que el navegador se
 * arrastre hasta morir (research R2).
 */
abstract class PivotDataset
{
    /** Máximo de filas que se le manda al navegador antes de pedir que acote el rango. */
    public const TOPE_FILAS = 50000;

    /** Columnas que no vienen de la consulta: se derivan de `fecha` en PHP. */
    private const DERIVADAS_DE_FECHA = ['anio', 'mes'];

    /** Meses en español, para que el cruce por mes no salga en inglés como el resto del sistema. */
    private const MESES = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    /**
     * Agrega `anio` y `mes` a la fila, derivados de su fecha.
     *
     * El mes se numera además del nombre (`08 · Ago`) para que el cruce ordene cronológicamente y
     * no alfabéticamente: con sólo el nombre, Abril quedaría antes que Agosto y Enero.
     */
    private function conFechaDesglosada(array $fila): array
    {
        $fecha = $fila['fecha'] ?? null;

        if (! $fecha) {
            $fila['anio'] = 'Sin fecha';
            $fila['mes'] = 'Sin fecha';

            return $fila;
        }

        $partes = explode('-', substr((string) $fecha, 0, 10));
        $mes = (int) ($partes[1] ?? 0);

        $fila['anio'] = $partes[0] ?? 'Sin fecha';
        $fila['mes'] = $mes ? sprintf('%02d · %s', $mes, self::MESES[$mes]) : 'Sin fecha';

        return $fila;
    }

    /** Nombre del informe, tal como lo conoce {@see DimensionesPivot}. */
    abstract protected function informe(): string;

    /** Consulta base ya filtrada: el mismo `detalle()` que usa la pantalla del informe. */
    abstract protected function consultaBase(Request $peticion): \Illuminate\Database\Query\Builder;

    public function __construct(protected DimensionesPivot $catalogo)
    {
    }

    /**
     * ¿El conjunto filtrado entra en el tope?
     *
     * Se cuenta antes de traer nada: si no entra, no tiene sentido materializar 200.000 filas
     * para después descartarlas.
     */
    public function excedeTope(Request $peticion): bool
    {
        return DB::query()->fromSub($this->consultaBase($peticion), 'd')->count() > self::TOPE_FILAS;
    }

    /**
     * @return array{filas: list<array<string, mixed>>, dimensiones: list<string>, datos: array<string, string>}
     */
    public function armar(Request $peticion): array
    {
        $informe = $this->informe();
        $dimensiones = $this->catalogo->dimensiones($informe);
        $medidas = $this->catalogo->medidas($informe);

        // Sólo las columnas que participan del cruce: el detalle proyecta muchas más (costos,
        // desglose impositivo, números de comprobante) que no son ni dimensión ni medida y sólo
        // engordarían la respuesta.
        //
        // `anio` y `mes` NO son columnas: se derivan de la fecha más abajo, en PHP. Hacerlo en SQL
        // obligaría a YEAR()/strftime() según el motor, que es justo la clase de diferencia entre
        // MySQL y la SQLite de los tests que ya nos hizo perder tiempo dos veces en esta spec.
        $columnas = collect($dimensiones)->pluck('columna')
            ->merge(collect($medidas)->pluck('columna'))
            ->reject(fn ($c) => in_array($c, self::DERIVADAS_DE_FECHA, true))
            ->unique()
            ->values()
            ->all();

        $filas = DB::query()
            ->fromSub($this->consultaBase($peticion), 'd')
            ->select($columnas)
            ->limit(self::TOPE_FILAS)
            ->get()
            ->map(fn ($fila) => $this->conFechaDesglosada((array) $fila))
            ->all();

        return [
            'filas' => $filas,
            'dimensiones' => collect($dimensiones)->map(fn ($d, $clave) => [
                'clave' => $clave,
                'rotulo' => $d['rotulo'],
                'columna' => $d['columna'],
            ])->values()->all(),
            'datos' => collect($medidas)->map(fn ($m, $clave) => [
                'clave' => $clave,
                'rotulo' => $m['rotulo'],
                'columna' => $m['columna'],
                'es_conteo' => $m['es_conteo'],
            ])->values()->all(),
        ];
    }
}
