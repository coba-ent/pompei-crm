<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Unifica las categorías de gasto que quedaron duplicadas por la importación desde Contagram.
 *
 * Las dos tandas de migración (05/08 y 10/08/2026) crearon la jerarquía de categorías cada una
 * por su lado, comparando el nombre **tal cual venía en el Excel**. Como el origen no es
 * consistente en plural ni en mayúsculas, quedaron pares como `Sueldo` / `Sueldos` (bajo
 * *Empleados*) o `Vivo verde` / `Vivo Verde` (bajo *Juan Personal*): la misma categoría dos veces
 * en el desplegable del alta de Gasto, que es justamente lo que confunde al cargar.
 *
 * **No** son duplicados los homónimos bajo padres distintos: `Alquiler / Juan Personal` y
 * `Alquiler / Oficina Pompei` son gastos de cosas diferentes y tienen que seguir separados. Por eso
 * el agrupamiento es por **(padre, nombre normalizado)** y nunca sólo por nombre.
 *
 * Normalización para comparar: minúsculas, sin acentos, sin espacios de sobra y sin la `s` final
 * (plural). Sólo para *detectar*: el nombre que sobrevive es el del ganador, tal cual está.
 *
 * Gana el que más gastos tiene (a igualdad, el de id menor: el más viejo). Los gastos del perdedor
 * se reapuntan al ganador y recién ahí se borra la categoría vacía, así no se pierde ningún gasto.
 * Si el perdedor tiene subcategorías o lo referencia otro módulo (compras, ventas, clientes,
 * proveedores, otros ingresos, configuración), se informa y **no se toca**: eso ya no es un
 * duplicado de la importación sino una categoría en uso.
 *
 * Antes de agrupar se resuelve un caso que la importación dejó y que **no** se ve como duplicado
 * en la base pero sí en pantalla: una categoría anidada dentro de otra del mismo nombre
 * (`Juan Personal` id 58 colgando de `Juan Personal` id 42). Las dos se dibujan igual en el
 * desplegable, así que sus hijas parecen repetidas aunque técnicamente cuelguen de padres
 * distintos. Se colapsa la interna contra la externa —moviendo sus gastos y subiendo sus
 * subcategorías un nivel— y recién después corre la unificación por (padre, nombre), que ahora sí
 * ve esas hijas bajo un mismo padre.
 *
 * Idempotente: una vez unificado, el grupo deja de tener más de una categoría y no hay nada que
 * hacer en la corrida siguiente.
 *
 * `categorias` NO usa SoftDeletes: el borrado es definitivo. Correr siempre `--dry-run` primero, y
 * en producción con backup de la tabla.
 */
class UnificarCategoriasGastoDuplicadas extends Command
{
    protected $signature = 'gastos:unificar-categorias-duplicadas {--dry-run : Muestra el plan sin escribir nada}';

    protected $description = 'Unifica categorías de gasto duplicadas por la importación (mismo padre y mismo nombre salvo plural/mayúsculas)';

    /** Tablas que apuntan a `categorias` y que bloquean el borrado si el perdedor está en uso. */
    private const REFERENCIAS = [
        'compras' => 'categoria_id',
        'ventas' => 'categoria_id',
        'presupuestos' => 'categoria_id',
        'clientes' => 'categoria_id',
        'proveedores' => 'categoria_id',
        'otros_ingresos' => 'categoria_id',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN: no se escribe nada.');
        }

        $anidadas = $this->colapsarAnidadasEnSiMismas($dryRun);

        $grupos = Categoria::where('tipo', 'gasto')
            ->get(['id', 'nombre', 'categoria_padre_id'])
            ->groupBy(fn (Categoria $c) => $c->categoria_padre_id.'|'.$this->normalizar($c->nombre))
            ->filter(fn ($g) => $g->count() > 1);

        if ($grupos->isEmpty() && $anidadas === 0) {
            $this->info('No hay categorías de gasto duplicadas.');

            return self::SUCCESS;
        }

        $unificadas = 0;
        $gastosMovidos = 0;
        $omitidas = 0;

        foreach ($grupos as $grupo) {
            $conteos = $grupo->mapWithKeys(fn (Categoria $c) => [$c->id => $this->gastosDe($c->id)]);

            // Gana la que más gastos tiene; a igualdad, la más vieja (id menor).
            $ganadora = $grupo->sortBy([
                fn (Categoria $a, Categoria $b) => $conteos[$b->id] <=> $conteos[$a->id],
                fn (Categoria $a, Categoria $b) => $a->id <=> $b->id,
            ])->first();

            $padre = $ganadora->categoria_padre_id
                ? Categoria::whereKey($ganadora->categoria_padre_id)->value('nombre')
                : '(raíz)';

            $this->line("<info>{$padre} → {$ganadora->nombre}</info> (id {$ganadora->id}, {$conteos[$ganadora->id]} gastos)");

            foreach ($grupo->where('id', '!=', $ganadora->id) as $perdedora) {
                $enUso = $this->referenciasDe($perdedora->id);

                if ($enUso !== []) {
                    $this->warn("   ! id {$perdedora->id} '{$perdedora->nombre}' NO se toca: en uso por ".implode(', ', $enUso));
                    $omitidas++;

                    continue;
                }

                $aMover = $conteos[$perdedora->id];
                $this->line("   - id {$perdedora->id} '{$perdedora->nombre}': {$aMover} gastos → id {$ganadora->id}, y se elimina");

                if (! $dryRun) {
                    DB::transaction(function () use ($perdedora, $ganadora) {
                        // Incluye los soft-deleted a propósito: si se restaura un gasto, tiene que
                        // apuntar a una categoría que exista.
                        DB::table('gastos')->where('categoria_id', $perdedora->id)
                            ->update(['categoria_id' => $ganadora->id]);
                        Categoria::whereKey($perdedora->id)->delete();
                    });
                }

                $gastosMovidos += $aMover;
                $unificadas++;
            }
        }

        $this->newLine();
        $this->info("Categorías unificadas: {$unificadas} | gastos reapuntados: {$gastosMovidos}"
            .($anidadas ? " | anidadas en sí mismas resueltas: {$anidadas}" : '')
            .($omitidas ? " | omitidas por estar en uso: {$omitidas}" : ''));

        if ($dryRun) {
            $this->warn('Nada de esto se escribió (dry-run).');
        }

        return self::SUCCESS;
    }

    /**
     * Colapsa las categorías que cuelgan de otra del mismo nombre: los gastos de la interna pasan a
     * la externa, sus subcategorías suben un nivel, y la interna se elimina. Devuelve cuántas
     * resolvió. En dry-run sólo informa.
     */
    private function colapsarAnidadasEnSiMismas(bool $dryRun): int
    {
        $resueltas = 0;

        $anidadas = Categoria::where('tipo', 'gasto')->whereNotNull('categoria_padre_id')->get()
            ->filter(function (Categoria $c) {
                $padre = Categoria::whereKey($c->categoria_padre_id)->value('nombre');

                return $padre !== null && $this->normalizar($padre) === $this->normalizar($c->nombre);
            });

        foreach ($anidadas as $interna) {
            $externaId = (int) $interna->categoria_padre_id;
            $gastos = $this->gastosDe($interna->id);
            $hijas = Categoria::where('categoria_padre_id', $interna->id)->count();

            $this->line("<info>Anidada en sí misma:</info> '{$interna->nombre}' (id {$interna->id}) dentro de id {$externaId}");
            $this->line("   - {$gastos} gastos y {$hijas} subcategorías pasan a id {$externaId}, y se elimina la interna");

            if (! $dryRun) {
                DB::transaction(function () use ($interna, $externaId) {
                    DB::table('gastos')->where('categoria_id', $interna->id)
                        ->update(['categoria_id' => $externaId]);
                    Categoria::where('categoria_padre_id', $interna->id)
                        ->update(['categoria_padre_id' => $externaId]);
                    Categoria::whereKey($interna->id)->delete();
                });
            }

            $resueltas++;
        }

        return $resueltas;
    }

    /** minúsculas, sin acentos, sin espacios de sobra y sin la `s` final del plural. */
    private function normalizar(string $nombre): string
    {
        $limpio = mb_strtolower(trim(preg_replace('/\s+/', ' ', $nombre)));
        $sinAcentos = strtr($limpio, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return rtrim($sinAcentos, 's');
    }

    private function gastosDe(int $categoriaId): int
    {
        return (int) DB::table('gastos')->where('categoria_id', $categoriaId)->whereNull('deleted_at')->count();
    }

    /** @return array<string> descripciones de lo que referencia a la categoría */
    private function referenciasDe(int $categoriaId): array
    {
        $enUso = [];

        if (Categoria::where('categoria_padre_id', $categoriaId)->exists()) {
            $enUso[] = 'subcategorías';
        }

        foreach (self::REFERENCIAS as $tabla => $columna) {
            $n = DB::table($tabla)->where($columna, $categoriaId)->count();
            if ($n > 0) {
                $enUso[] = "{$tabla} ({$n})";
            }
        }

        $config = DB::table('configuracion_ventas')
            ->where('categoria_id', $categoriaId)
            ->orWhere('categoria_compra_id', $categoriaId)
            ->count();

        if ($config > 0) {
            $enUso[] = 'configuración de ventas';
        }

        return $enUso;
    }
}
