<?php

namespace App\Services\MercadoLibre;

use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\User;

/**
 * Reemplaza el alta manual por selector (`store()`, spec 012) por un mecanismo
 * 100% automático (spec 021 reemplazo): compara el `sku_vendedor` visto en la
 * orden sincronizada más reciente de cada publicación pendiente contra el `id`
 * (clave primaria) de `productos` — sin campo nuevo (research.md R2).
 */
class VinculadorAutomatico
{
    /**
     * @return array{total: int, vinculadas: int, fallidas: int, detalle_fallidas: array<int, array{referencia: string, motivo: string, detalle?: string}>}
     */
    public function ejecutar(?User $usuario): array
    {
        $publicacionesVinculadas = MercadoLibrePublicacionProducto::pluck('ml_item_id')->all();

        // Mismo criterio "más reciente" que MercadoLibreVinculacionController::publicacionesPendientes():
        // orderByDesc('id') + unique() se queda con la primera aparición de cada
        // ml_item_id, que es la de mayor id (la más reciente).
        $pendientes = MercadoLibreOrdenItem::whereNotIn('ml_item_id', $publicacionesVinculadas)
            ->whereNull('ml_variation_id')
            ->select('ml_item_id', 'sku_vendedor', 'titulo')
            ->orderByDesc('id')
            ->get()
            ->unique('ml_item_id');

        $productosVinculados = array_fill_keys(
            MercadoLibrePublicacionProducto::pluck('producto_id')->all(),
            true
        );

        $vinculadas = 0;
        $detalleFallidas = [];

        foreach ($pendientes as $item) {
            $resultado = $this->procesar($item, $productosVinculados, $usuario);

            if ($resultado === null) {
                $vinculadas++;
            } else {
                $detalleFallidas[] = $resultado;
            }
        }

        return [
            'total' => $pendientes->count(),
            'vinculadas' => $vinculadas,
            'fallidas' => count($detalleFallidas),
            'detalle_fallidas' => $detalleFallidas,
        ];
    }

    /**
     * @param  array<int, bool>  $productosVinculados  índice de producto_id => true, mutado in-place a medida que se crean vínculos en esta misma corrida.
     * @return array{referencia: string, motivo: string, detalle?: string}|null null = vinculado con éxito.
     */
    private function procesar(MercadoLibreOrdenItem $item, array &$productosVinculados, ?User $usuario): ?array
    {
        $skuVendedor = trim((string) $item->sku_vendedor);

        if ($skuVendedor === '') {
            return ['referencia' => $item->ml_item_id, 'motivo' => 'sin_sku'];
        }

        // FR-002: sin excluir productos inactivos. Un SKU no numérico (ej. "ABC")
        // castea a 0, que nunca matchea un id real — mismo motivo que cualquier
        // SKU sin coincidencia (CHK001).
        $producto = Producto::find((int) $skuVendedor);

        if (! $producto) {
            return ['referencia' => $item->ml_item_id, 'motivo' => 'producto_no_encontrado'];
        }

        if (isset($productosVinculados[$producto->id])) {
            return ['referencia' => $item->ml_item_id, 'motivo' => 'ya_vinculado', 'detalle' => 'producto'];
        }

        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => $item->ml_item_id,
            'producto_id' => $producto->id,
            'titulo_ml' => $item->titulo,
            'vinculada_por' => $usuario?->id,
        ]);

        $productosVinculados[$producto->id] = true;

        return null;
    }
}
