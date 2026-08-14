<?php

namespace App\Services\MercadoLibre;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;

/**
 * Mantiene al día `listing_type_id` de cada vínculo (spec 050, research.md
 * R1/R3/R4): consulta `GET /items?ids=...` en chunks de hasta 20 (límite de
 * la API de ML), usado tanto por el comando diario (`sincronizarTodos()`,
 * que también cubre el backfill — no hay comando separado, R4) como al
 * vincular una publicación nueva (`sincronizarUno()`, FR-003).
 *
 * Ante fallo de un chunk o de un ítem puntual, los vínculos afectados
 * conservan su último `listing_type_id` conocido — no se abortan los demás
 * chunks ni se pisa el dato con `null` (Edge Case de la spec).
 */
class SincronizadorTiposPublicacion
{
    private const TAMANO_CHUNK = 20;

    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
    ) {
    }

    /**
     * Recorre TODOS los vínculos (no sólo los sin clasificar) — misma
     * operación sirve de backfill inicial y de refresco periódico (R4).
     *
     * @return array{actualizados: int, con_error: int}
     */
    public function sincronizarTodos(): array
    {
        $actualizados = 0;
        $conError = 0;

        MercadoLibrePublicacionProducto::query()
            ->select(['id', 'ml_item_id'])
            ->orderBy('id')
            ->chunk(self::TAMANO_CHUNK, function ($vinculos) use (&$actualizados, &$conError): void {
                $resultado = $this->consultarYPersistir($vinculos->pluck('ml_item_id')->all());
                $actualizados += $resultado['actualizados'];
                $conError += $resultado['con_error'];
            });

        MercadoLibreConfiguracion::actual()->update(['tipo_publicacion_ultima_sync_en' => now()]);

        return ['actualizados' => $actualizados, 'con_error' => $conError];
    }

    /**
     * Clasifica un único vínculo recién creado.
     *
     * ⚠️ spec 065/T007a: **nadie lo invoca**. El único punto que crea vínculos es
     * `VinculadorAutomatico`, que resuelve `listing_type_id`, `logistic_type` e
     * `inventory_id` del multiget que ya hace de por sí, sin una llamada extra por
     * publicación. Se conserva por si hace falta reclasificar un vínculo suelto a mano.
     */
    public function sincronizarUno(MercadoLibrePublicacionProducto $vinculo): bool
    {
        $resultado = $this->consultarYPersistir([$vinculo->ml_item_id]);

        return $resultado['actualizados'] > 0;
    }

    /** @return array{actualizados: int, con_error: int} */
    private function consultarYPersistir(array $mlItemIds): array
    {
        if (! $mlItemIds) {
            return ['actualizados' => 0, 'con_error' => 0];
        }

        $respuesta = $this->cliente->obtener('sincronizar_tipo_publicacion', '/items', ['ids' => implode(',', $mlItemIds)]);

        if ($respuesta->fallo()) {
            return ['actualizados' => 0, 'con_error' => count($mlItemIds)];
        }

        $actualizados = 0;
        $conError = 0;

        foreach ($respuesta->datos as $entrada) {
            if (($entrada['code'] ?? null) !== 200) {
                $conError++;

                continue;
            }

            $itemId = $entrada['body']['id'] ?? null;
            $listingTypeId = $entrada['body']['listing_type_id'] ?? null;

            if (! $itemId || ! $listingTypeId) {
                $conError++;

                continue;
            }

            // spec 065/FR-001: el tipo de logística y el inventario viajan en ESTE mismo body,
            // así que clasificar Full no cuesta ninguna llamada nueva (research R8). Igual que
            // con listing_type_id, un campo ausente conserva el último valor conocido en vez de
            // pisarse con null (FR-004): una publicación mal clasificada como no-Full recibiría
            // stock que Mercado Libre no puede escribir.
            $logisticType = $entrada['body']['shipping']['logistic_type'] ?? null;
            $inventoryId = $entrada['body']['inventory_id'] ?? null;

            $filas = MercadoLibrePublicacionProducto::where('ml_item_id', $itemId)->update(array_filter([
                'logistic_type' => $logisticType,
                'inventory_id' => $inventoryId,
                'logistica_sincronizada_en' => $logisticType ? now() : null,
            ], static fn ($valor) => $valor !== null) + [
                'listing_type_id' => $listingTypeId,
                'listing_type_sincronizado_en' => now(),
            ]);

            $actualizados += $filas;
        }

        return ['actualizados' => $actualizados, 'con_error' => $conError];
    }
}
