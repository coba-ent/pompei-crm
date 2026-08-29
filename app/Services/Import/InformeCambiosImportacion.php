<?php

namespace App\Services\Import;

use App\Models\CompraItem;
use App\Models\Deposito;
use App\Models\ImportacionCorrida;
use App\Models\ListaPrecio;
use App\Models\MovimientoStock;
use App\Models\PrecioProducto;
use App\Models\Producto;
use App\Models\Stock;
use App\Models\VentaItem;

/**
 * Informe de "qué cambió" de una corrida de import (spec 093, US1).
 *
 * Compara el estado guardado en `importacion_filas_snapshot` ANTES de la corrida contra el
 * producto **de hoy**. Eso significa que un cambio posterior a la importación (una venta, una
 * edición a mano) también aparece acá: la advertencia viaja en la respuesta (`advertencia_metodo`)
 * y las filas con actividad posterior quedan marcadas (FR-005, FR-006).
 *
 * Es de SÓLO LECTURA (FR-011): no escribe productos, precios ni stock.
 *
 * ⚠️ `precios_anteriores` y `stock_anterior` del snapshot son **arrays de objetos**
 * (`[{lista_precio_id, precio}]`), NO mapas `id => valor`. Leerlos como mapa toma el índice del
 * array por id de lista: el primer intento de armar este informe reportó "192 productos cambiaron
 * en las 11 listas" cuando la respuesta real era ninguno. `InformeCambiosImportacionTest` lo fija.
 *
 * ⚠️ Rendimiento: la corrida más grande hasta hoy tiene 1.117 filas. Todas las consultas son
 * **agregadas por corrida** (productos, precios y stock de todos los ids de una vez) y la
 * comparación se hace en memoria — una consulta por fila serían más de 3.000 queries.
 */
class InformeCambiosImportacion
{
    /** Campos del producto que se comparan y cómo se los nombra en el informe. */
    private const CAMPOS = [
        'nombre' => 'Nombre',
        'codigo' => 'Código',
        'costo' => 'Costo',
        'precio_venta' => 'Precio de venta',
        'descripcion' => 'Descripción',
        'marca_id' => 'Marca',
        'categoria_id' => 'Categoría',
        'unidad_medida_id' => 'Unidad de medida',
        'tipo_producto_id' => 'Tipo de producto',
        'proveedor_id' => 'Proveedor',
        'codigo_barras' => 'Código de barras',
        'punto_reposicion' => 'Punto de reposición',
        'peso' => 'Peso',
        'activo' => 'Activo',
    ];

    private const ADVERTENCIA = 'Compara el estado guardado antes de la importación contra el producto de hoy. Un cambio posterior (una venta, una edición) también aparece acá.';

    /** @return array<string, mixed> el cuerpo de la respuesta del contrato (sección 2). */
    public function generar(ImportacionCorrida $corrida): array
    {
        $filas = $corrida->filas()->get();

        if ($filas->isEmpty()) {
            return [
                'ok' => true,
                'informe_disponible' => false,
                'motivo' => 'Esta importación es anterior al registro de detalle. No hay información de qué cambió.',
            ];
        }

        $productoIds = $filas->pluck('producto_id')->filter()->unique()->values()->all();

        $productos = Producto::whereIn('id', $productoIds)->get()->keyBy('id');
        $preciosActuales = $this->preciosActuales($productoIds);
        $stockActual = $this->stockActual($productoIds);
        $actividad = $this->actividadPosterior($filas, $productoIds);

        $nombresListas = ListaPrecio::whereIn('id', $this->listasReferenciadas($filas, $preciosActuales))
            ->pluck('nombre', 'id')->all();
        $nombresDepositos = Deposito::whereIn('id', $this->depositosReferenciados($filas, $stockActual))
            ->pluck('nombre', 'id')->all();

        $campos = [];      // campo => ['productos' => n, 'ejemplo' => [...]]
        $precios = [];     // lista_precio_id => ['productos' => n, 'ejemplo' => [...]]
        $stock = [];
        $conCambio = 0;
        $eliminados = 0;
        $conActividad = 0;

        foreach ($filas as $fila) {
            $producto = $fila->producto_id ? ($productos[$fila->producto_id] ?? null) : null;
            $eliminado = $producto === null;
            $tuvoActividad = (bool) ($actividad[$fila->producto_id] ?? false);

            if ($eliminado) {
                $eliminados++;
            }
            if ($tuvoActividad) {
                $conActividad++;
            }

            $cambio = false;
            $codigo = $this->codigoDe($fila, $producto);

            if (! $eliminado) {
                $cambio = $this->acumularCampos($fila, $producto, $codigo, $campos) || $cambio;
                $cambio = $this->acumularPrecios($fila, $preciosActuales[$fila->producto_id] ?? [], $codigo, $precios) || $cambio;
            }

            $cambioStock = $this->acumularStock(
                $fila,
                $stockActual[$fila->producto_id] ?? [],
                $codigo,
                $eliminado,
                $tuvoActividad,
                $nombresDepositos,
                $stock,
            );

            if ($cambioStock || $cambio) {
                $conCambio++;
            }
        }

        // FR-004: el cambio de stock más grande primero — es el que uno quiere ver.
        usort($stock, fn ($a, $b) => abs($b['diferencia']) <=> abs($a['diferencia']));

        return [
            'ok' => true,
            'informe_disponible' => true,
            'corrida' => [
                'id' => $corrida->id,
                'archivo_original' => $corrida->archivo_original,
                'confirmado_en' => $corrida->confirmado_en?->toIso8601String(),
                'usuario' => $corrida->usuario?->name,
                'deshecha_en' => $corrida->deshecho_en?->toIso8601String(),
                'filas_totales' => (int) $corrida->filas_creadas + (int) $corrida->filas_actualizadas,
                'filas_con_detalle' => $filas->count(),
            ],
            'advertencia_metodo' => self::ADVERTENCIA,
            'resumen' => [
                'productos_con_algun_cambio' => $conCambio,
                'productos_sin_cambios' => $filas->count() - $conCambio,
                'con_actividad_posterior' => $conActividad,
                'productos_eliminados' => $eliminados,
            ],
            'campos' => $this->formatearCampos($campos),
            'precios' => $this->formatearPrecios($precios, $nombresListas),
            'stock' => array_values($stock),
        ];
    }

    /** @return bool si algún campo del producto cambió */
    private function acumularCampos($fila, Producto $producto, string $codigo, array &$campos): bool
    {
        $anterior = $fila->estado_anterior ?? [];
        $hubo = false;

        foreach (self::CAMPOS as $campo => $_etiqueta) {
            if (! array_key_exists($campo, $anterior)) {
                continue;
            }

            $antes = $anterior[$campo];
            $ahora = $producto->{$campo};

            if ($this->iguales($antes, $ahora)) {
                continue;
            }

            $hubo = true;
            $campos[$campo] ??= ['productos' => 0, 'ejemplo' => null];
            $campos[$campo]['productos']++;
            $campos[$campo]['ejemplo'] ??= [
                'codigo' => $codigo,
                'antes' => $this->texto($antes),
                'ahora' => $this->texto($ahora),
            ];
        }

        return $hubo;
    }

    /**
     * @param  array<int, mixed>  $actuales  lista_precio_id => precio actual
     * @return bool si algún precio cambió
     */
    private function acumularPrecios($fila, array $actuales, string $codigo, array &$precios): bool
    {
        $hubo = false;

        // ⚠️ array de objetos, no mapa: se recorre y se lee `lista_precio_id` de cada uno.
        foreach (($fila->precios_anteriores ?? []) as $anterior) {
            if (! is_array($anterior) || ! isset($anterior['lista_precio_id'])) {
                continue;
            }

            $listaId = (int) $anterior['lista_precio_id'];
            $antes = (float) $anterior['precio'];

            if (! array_key_exists($listaId, $actuales)) {
                continue; // la lista o el precio ya no existen — no se inventa un cambio
            }

            $ahora = (float) $actuales[$listaId];

            if ($this->mismoNumero($antes, $ahora)) {
                continue;
            }

            $hubo = true;
            $precios[$listaId] ??= ['productos' => 0, 'ejemplo' => null];
            $precios[$listaId]['productos']++;
            $precios[$listaId]['ejemplo'] ??= [
                'codigo' => $codigo,
                'antes' => $antes,
                'ahora' => $ahora,
                'variacion_pct' => $antes != 0.0 ? round((($ahora - $antes) / $antes) * 100, 2) : null,
            ];
        }

        return $hubo;
    }

    /**
     * @param  array<int, mixed>  $actuales  deposito_id => cantidad actual
     * @return bool si el stock cambió en algún depósito
     */
    private function acumularStock($fila, array $actuales, string $codigo, bool $eliminado, bool $tuvoActividad, array $nombresDepositos, array &$stock): bool
    {
        $hubo = false;

        foreach (($fila->stock_anterior ?? []) as $anterior) {
            if (! is_array($anterior) || ! isset($anterior['deposito_id'])) {
                continue;
            }

            $depositoId = (int) $anterior['deposito_id'];
            $antes = (float) $anterior['cantidad'];
            $ahora = (float) ($actuales[$depositoId] ?? 0);

            if ($this->mismoNumero($antes, $ahora)) {
                continue;
            }

            $hubo = true;
            $stock[] = [
                'producto_id' => $fila->producto_id,
                'codigo' => $codigo,
                'nombre' => $this->nombreDe($fila),
                // El depósito puede haberse eliminado después de la importación: se lo nombra
                // por id en vez de romper el informe entero.
                'deposito' => $nombresDepositos[$depositoId] ?? "Depósito #{$depositoId}",
                'antes' => $antes,
                'ahora' => $ahora,
                'diferencia' => round($ahora - $antes, 3),
                'actividad_posterior' => $tuvoActividad,
                'producto_eliminado' => $eliminado,
            ];
        }

        return $hubo;
    }

    /**
     * Qué productos tuvieron ventas, compras o movimientos de stock DESPUÉS de la importación
     * (FR-006). Reusa los `limite_*` que el snapshot ya guarda para el deshacer — no se inventa un
     * mecanismo nuevo. Tres consultas en total, no tres por fila.
     *
     * @return array<int, bool> producto_id => tuvo actividad
     */
    private function actividadPosterior($filas, array $productoIds): array
    {
        if ($productoIds === []) {
            return [];
        }

        $limites = [];
        foreach ($filas as $fila) {
            if (! $fila->producto_id) {
                continue;
            }
            $limites[$fila->producto_id] = [
                'venta' => (int) ($fila->limite_venta_item_id ?? 0),
                'compra' => (int) ($fila->limite_compra_item_id ?? 0),
                'movimiento' => (int) ($fila->limite_movimiento_stock_id ?? 0),
            ];
        }

        $resultado = array_fill_keys(array_keys($limites), false);

        $fuentes = [
            'venta' => VentaItem::whereIn('producto_id', $productoIds)
                ->selectRaw('producto_id, MAX(id) as max_id')->groupBy('producto_id')->pluck('max_id', 'producto_id'),
            'compra' => CompraItem::whereIn('producto_id', $productoIds)
                ->selectRaw('producto_id, MAX(id) as max_id')->groupBy('producto_id')->pluck('max_id', 'producto_id'),
            'movimiento' => MovimientoStock::whereIn('producto_id', $productoIds)
                ->selectRaw('producto_id, MAX(id) as max_id')->groupBy('producto_id')->pluck('max_id', 'producto_id'),
        ];

        foreach ($fuentes as $clave => $maximos) {
            foreach ($maximos as $productoId => $maxId) {
                $productoId = (int) $productoId;
                if (isset($limites[$productoId]) && (int) $maxId > $limites[$productoId][$clave]) {
                    $resultado[$productoId] = true;
                }
            }
        }

        return $resultado;
    }

    /** @return array<int, array<int, mixed>> producto_id => [lista_precio_id => precio] */
    private function preciosActuales(array $productoIds): array
    {
        $mapa = [];

        foreach (PrecioProducto::whereIn('producto_id', $productoIds)->get(['producto_id', 'lista_precio_id', 'precio']) as $p) {
            $mapa[$p->producto_id][(int) $p->lista_precio_id] = $p->precio;
        }

        return $mapa;
    }

    /** @return array<int, array<int, float>> producto_id => [deposito_id => cantidad] */
    private function stockActual(array $productoIds): array
    {
        $mapa = [];

        foreach (Stock::whereIn('producto_id', $productoIds)->get(['producto_id', 'deposito_id', 'cantidad']) as $s) {
            $depositoId = (int) $s->deposito_id;
            // Un producto con variantes tiene varias filas por depósito: se suman, que es lo que
            // el snapshot guardó como cantidad del depósito.
            $mapa[$s->producto_id][$depositoId] = (float) ($mapa[$s->producto_id][$depositoId] ?? 0) + (float) $s->cantidad;
        }

        return $mapa;
    }

    private function listasReferenciadas($filas, array $preciosActuales): array
    {
        $ids = [];
        foreach ($filas as $fila) {
            foreach (($fila->precios_anteriores ?? []) as $p) {
                if (is_array($p) && isset($p['lista_precio_id'])) {
                    $ids[(int) $p['lista_precio_id']] = true;
                }
            }
        }
        foreach ($preciosActuales as $porLista) {
            foreach (array_keys($porLista) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    private function depositosReferenciados($filas, array $stockActual): array
    {
        $ids = [];
        foreach ($filas as $fila) {
            foreach (($fila->stock_anterior ?? []) as $s) {
                if (is_array($s) && isset($s['deposito_id'])) {
                    $ids[(int) $s['deposito_id']] = true;
                }
            }
        }
        foreach ($stockActual as $porDeposito) {
            foreach (array_keys($porDeposito) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    private function codigoDe($fila, ?Producto $producto): string
    {
        return (string) ($producto->codigo ?? $fila->estado_anterior['codigo'] ?? ('Producto #'.$fila->producto_id));
    }

    private function nombreDe($fila): string
    {
        return (string) ($fila->estado_anterior['nombre'] ?? '—');
    }

    /** @return array<int, array<string, mixed>> ordenado por cantidad de productos afectados */
    private function formatearCampos(array $campos): array
    {
        $salida = [];
        foreach ($campos as $campo => $datos) {
            $salida[] = [
                'campo' => $campo,
                'etiqueta' => self::CAMPOS[$campo] ?? $campo,
                'productos' => $datos['productos'],
                'ejemplo' => $datos['ejemplo'],
            ];
        }
        usort($salida, fn ($a, $b) => $b['productos'] <=> $a['productos']);

        return $salida;
    }

    /** @return array<int, array<string, mixed>> */
    private function formatearPrecios(array $precios, array $nombresListas): array
    {
        $salida = [];
        foreach ($precios as $listaId => $datos) {
            $salida[] = [
                'lista_precio_id' => $listaId,
                // La lista puede haberse eliminado: se la nombra por id, el informe no rompe.
                'lista' => $nombresListas[$listaId] ?? "Lista #{$listaId}",
                'productos' => $datos['productos'],
                'ejemplo' => $datos['ejemplo'],
            ];
        }
        usort($salida, fn ($a, $b) => $b['productos'] <=> $a['productos']);

        return $salida;
    }

    /** Compara evitando falsos positivos entre "100.00" y 100, y entre null y "". */
    private function iguales($antes, $ahora): bool
    {
        if ($antes === null || $ahora === null) {
            return (string) $antes === (string) $ahora;
        }

        if (is_numeric($antes) && is_numeric($ahora)) {
            return $this->mismoNumero((float) $antes, (float) $ahora);
        }

        if (is_bool($antes) || is_bool($ahora)) {
            return (bool) $antes === (bool) $ahora;
        }

        return (string) $antes === (string) $ahora;
    }

    private function mismoNumero(float $a, float $b): bool
    {
        return abs($a - $b) < 0.0001;
    }

    private function texto($valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }

        return (string) $valor;
    }
}
