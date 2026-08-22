<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * spec 073 — migra el punto de reposición desde la lista de precios "Punto Reposición" (donde lo
 * dejó la importación de datos reales) hacia `productos.punto_reposicion`, y elimina esa lista.
 *
 * TOCA DATOS REALES DEL NEGOCIO, así que el contrato es conservador por diseño:
 * dry-run por defecto, transacción, idempotencia, y **sin modo forzado** para el borrado — lo que
 * se rompería del otro lado son precios de venta reales.
 */
class MigrarPuntoReposicion extends Command
{
    protected $signature = 'migracion:punto-reposicion
                            {--aplicar : Escribe productos.punto_reposicion. Sin este flag no escribe nada}
                            {--eliminar-lista : Sólo con --aplicar. Tras migrar, verifica referencias y borra la lista}';

    protected $description = 'Migra el Punto de Reposición desde la lista de precios homónima a productos.punto_reposicion';

    /**
     * Columnas que podrían referenciar la lista. Si alguna tiene filas, no se borra nada.
     *
     * Los nombres son los reales de esta base: la configuración de ventas de la empresa vive en
     * `configuracion_ventas` y la de Tiendanube en `tn_conexion_rest`.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const REFERENCIAS = [
        ['clientes', 'lista_precio_id'],
        ['ventas', 'lista_precio_id'],
        ['presupuestos', 'lista_precio_id'],
        ['ml_configuracion', 'lista_precio_id'],
        ['ml_configuracion', 'lista_precio_id_premium'],
        ['tn_conexion_rest', 'lista_precio_id'],
        ['configuracion_ventas', 'lista_precio_id'],
    ];

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $eliminar = (bool) $this->option('eliminar-lista');

        if ($eliminar && ! $aplicar) {
            $this->error('--eliminar-lista sólo se puede usar junto con --aplicar.');

            return self::FAILURE;
        }

        $candidatas = $this->listasCandidatas();

        if ($candidatas->count() > 1) {
            $this->error('Hay más de una lista llamada "Punto Reposición" (ids: '
                .$candidatas->pluck('id')->implode(', ').'). No se puede decidir cuál migrar.');

            return self::FAILURE;
        }

        $lista = $candidatas->first();

        if ($lista === null) {
            // Idempotencia: correrlo después de haber eliminado la lista no es un error.
            $this->info('No hay ninguna lista de precios "Punto Reposición": no hay nada que migrar.');
            $this->line('Los puntos de reposición ya cargados en productos quedan intactos.');

            return self::SUCCESS;
        }

        $this->info("Lista de precios encontrada: id {$lista->id} \"{$lista->nombre}\"");
        $this->newLine();

        $resumen = $this->analizar($lista->id);
        $this->imprimirResumen($resumen);

        $referencias = $this->contarReferencias($lista->id);
        $this->imprimirReferencias($referencias);
        $sePuedeEliminar = array_sum($referencias) === 0;

        if (! $aplicar) {
            $this->newLine();
            $this->warn('MODO DRY-RUN — no se escribió nada. Volvé a correr con --aplicar.');

            return self::SUCCESS;
        }

        if ($eliminar && ! $sePuedeEliminar) {
            $this->newLine();
            $this->error('Hay referencias a la lista: no se borró nada y tampoco se migró.');
            $this->line('Resolvé esas referencias antes de volver a intentar. No existe un modo forzado.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($resumen, $lista, $eliminar) {
            foreach ($resumen['aEscribir'] as $productoId => $valor) {
                DB::table('productos')->where('id', $productoId)
                    ->update(['punto_reposicion' => $valor, 'updated_at' => now()]);
            }

            if ($eliminar) {
                DB::table('precios_producto')->where('lista_precio_id', $lista->id)->delete();
                DB::table('listas_precio')->where('id', $lista->id)->delete();
            }
        });

        $this->newLine();
        $this->info('Migrados '.count($resumen['aEscribir']).' productos.');

        if ($eliminar) {
            $this->info("Lista {$lista->id} eliminada junto con sus precios.");
            $this->line('La columna de esa lista desaparece sola del listado de Productos y del export.');
        } else {
            $this->line('La lista NO se borró. Para borrarla: --aplicar --eliminar-lista');
        }

        return self::SUCCESS;
    }

    /**
     * Identifica la lista por nombre, insensible a mayúsculas y acentos. Si no encuentra
     * exactamente una, no adivina por id: el 14 es de esta base y no tiene por qué serlo en otra.
     */
    private function listasCandidatas(): \Illuminate\Support\Collection
    {
        return DB::table('listas_precio')->get()->filter(
            fn ($l) => $this->normalizar($l->nombre) === 'punto reposicion'
        )->values();
    }

    private function normalizar(?string $texto): string
    {
        $texto = mb_strtolower(trim((string) $texto));
        $acentos = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];

        return strtr($texto, $acentos);
    }

    /**
     * @return array{aEscribir: array<int, int>, enteros: int, redondeados: int,
     *               noInterpretables: array<int, array<string, mixed>>, yaTenian: int,
     *               conValor: int, sinValor: int}
     */
    private function analizar(int $listaId): array
    {
        $filas = DB::table('precios_producto as pp')
            ->join('productos as p', 'p.id', '=', 'pp.producto_id')
            ->where('pp.lista_precio_id', $listaId)
            ->select('p.id', 'p.nombre', 'p.punto_reposicion', 'pp.precio')
            ->orderBy('p.id')
            ->get();

        $resumen = [
            'aEscribir' => [],
            'enteros' => 0,
            'redondeados' => 0,
            'noInterpretables' => [],
            'yaTenian' => 0,
            'conValor' => $filas->count(),
            'sinValor' => 0,
        ];

        foreach ($filas as $f) {
            // La migración es para poblar, no para sobrescribir: lo cargado a mano se respeta.
            if ($f->punto_reposicion !== null) {
                $resumen['yaTenian']++;

                continue;
            }

            $valor = $f->precio;

            // Negativo, nulo y cero significan lo mismo: el producto no se controla.
            if ($valor === null || (float) $valor < 0 || (float) $valor == 0.0) {
                $resumen['noInterpretables'][] = [
                    'id' => $f->id,
                    'nombre' => $f->nombre,
                    'valor' => $valor,
                ];

                continue;
            }

            $numero = (float) $valor;
            $entero = (int) round($numero);

            if ($numero == (float) $entero) {
                $resumen['enteros']++;
            } else {
                $resumen['redondeados']++;
            }

            $resumen['aEscribir'][$f->id] = $entero;
        }

        $total = (int) DB::table('productos')->count();
        $resumen['sinValor'] = max(0, $total - $filas->count());

        return $resumen;
    }

    /** @param array<string, mixed> $r */
    private function imprimirResumen(array $r): void
    {
        $this->line('  Productos con valor en la lista ....... '.$r['conValor']);
        $this->line('    → migrados con valor entero ......... '.$r['enteros']);
        $this->line('    → redondeados (tenían decimales) .... '.$r['redondeados']);
        $this->line('    → no interpretables (negativo/nulo) . '.count($r['noInterpretables']));
        $this->line('    → ya tenían valor cargado a mano .... '.$r['yaTenian']);
        $this->line('  Productos sin valor (quedan en null) .. '.$r['sinValor']);

        if ($r['noInterpretables'] !== []) {
            $this->newLine();
            $this->line('  Productos no interpretables:');
            foreach (array_slice($r['noInterpretables'], 0, 50) as $p) {
                $valor = $p['valor'] === null ? 'null' : $p['valor'];
                $this->line(sprintf('    #%-6s %-40s valor: %s', $p['id'], mb_substr((string) $p['nombre'], 0, 40), $valor));
            }
            if (count($r['noInterpretables']) > 50) {
                $this->line('    … y '.(count($r['noInterpretables']) - 50).' más.');
            }
        }

        $this->newLine();
    }

    /** @return array<string, int> */
    private function contarReferencias(int $listaId): array
    {
        $conteos = [];

        foreach (self::REFERENCIAS as [$tabla, $columna]) {
            $conteos["{$tabla}.{$columna}"] = DB::table($tabla)->where($columna, $listaId)->count();
        }

        return $conteos;
    }

    /** @param array<string, int> $referencias */
    private function imprimirReferencias(array $referencias): void
    {
        $this->line('Verificación de referencias a la lista:');

        foreach ($referencias as $donde => $cuantas) {
            $this->line(sprintf('    %-42s %s', str_pad($donde.' ', 42, '.'), $cuantas));
        }

        if (array_sum($referencias) === 0) {
            $this->info('  ✔ Nada la referencia: se puede eliminar.');
        } else {
            $this->error('  ✘ Algo la referencia: NO se puede eliminar.');
        }
    }
}
