<?php

namespace App\Services\MercadoLibre;

use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\User;
use App\Services\MercadoLibre\Excepciones\VinculacionAutomaticaFallidaException;

/**
 * Reemplaza el alta manual por selector (`store()`, spec 012) por un mecanismo
 * 100% automático (spec 021) que ahora resuelve el SKU vigente contra el
 * catálogo en vivo del vendedor conectado (spec 023 reemplazo, research.md
 * R1/R2/R3) — deja de depender de que la publicación haya vendido alguna vez.
 */
class VinculadorAutomatico
{
    private const TAMANIO_CHUNK_MULTIGET = 20;

    public function __construct(private ClienteMercadoLibre $cliente) {}

    /**
     * @return array{total: int, vinculadas: int, fallidas: int, detalle_fallidas: array<int, array{referencia: string, motivo: string, detalle?: string}>}
     */
    public function ejecutar(?User $usuario): array
    {
        $sellerId = MercadoLibreCuenta::conectada()->value('ml_user_id');

        $publicacionesVinculadas = array_fill_keys(
            MercadoLibrePublicacionProducto::pluck('ml_item_id')->all(),
            true
        );

        $idsPorProcesar = array_values(array_filter(
            $this->recorrerCatalogo($sellerId),
            fn (string $id) => ! isset($publicacionesVinculadas[$id])
        ));

        $total = 0;
        $vinculadas = 0;
        $detalleFallidas = [];

        foreach (array_chunk($idsPorProcesar, self::TAMANIO_CHUNK_MULTIGET) as $chunk) {
            foreach ($this->detalleDePublicaciones($chunk) as $detalle) {
                if ($detalle === null) {
                    continue; // status=closed o con variantes (FR-003/FR-007): ni cuenta ni se procesa.
                }

                $total++;
                $resultado = $this->procesar($detalle, $usuario);

                if ($resultado === null) {
                    $vinculadas++;
                } else {
                    $detalleFallidas[] = $resultado;
                }
            }
        }

        return [
            'total' => $total,
            'vinculadas' => $vinculadas,
            'fallidas' => count($detalleFallidas),
            'detalle_fallidas' => $detalleFallidas,
        ];
    }

    /**
     * Recorre `/users/{seller_id}/items/search` en modo `scan` hasta agotar el
     * catálogo (research.md R1): primera llamada sin `scroll_id`, siguientes
     * con el `scroll_id` de la respuesta anterior, hasta que `results` viene
     * vacío. Aborta la corrida completa si el catálogo en vivo falla a mitad
     * de camino (spec.md Assumptions).
     *
     * @return array<int, string>
     */
    private function recorrerCatalogo(?string $sellerId): array
    {
        $ids = [];
        $scrollId = null;

        do {
            $opciones = ['search_type' => 'scan'];
            if ($scrollId !== null) {
                $opciones['scroll_id'] = $scrollId;
            }

            $respuesta = $this->cliente->obtener('vincular_automatico_scan', "/users/{$sellerId}/items/search", $opciones);

            if ($respuesta->fallo()) {
                throw new VinculacionAutomaticaFallidaException(
                    "No se pudo completar la vinculación automática: {$respuesta->mensajeError}."
                );
            }

            $resultados = $respuesta->datos['results'] ?? [];
            array_push($ids, ...$resultados);
            $scrollId = $respuesta->datos['scroll_id'] ?? null;
        } while ($resultados !== []);

        return $ids;
    }

    /**
     * Multiget `GET /items?ids=...` en chunks de 20 (research.md R2). Por cada
     * entrada: `null` si está excluida (status=closed o con variantes), o el
     * detalle mínimo para resolver el SKU (research.md R3).
     *
     * @param  array<int, string>  $ids
     * @return array<int, array{ml_item_id: string, sku: ?string, titulo: ?string}|null>
     */
    private function detalleDePublicaciones(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $respuesta = $this->cliente->obtener('vincular_automatico_multiget', '/items', ['ids' => implode(',', $ids)]);

        if ($respuesta->fallo()) {
            throw new VinculacionAutomaticaFallidaException(
                "No se pudo completar la vinculación automática: {$respuesta->mensajeError}."
            );
        }

        return collect($respuesta->datos)
            ->map(function (array $entrada) {
                $item = $entrada['body'] ?? [];

                if (($item['status'] ?? null) === 'closed' || ! empty($item['variations'])) {
                    return null;
                }

                $sku = collect($item['attributes'] ?? [])
                    ->first(fn ($atributo) => ($atributo['id'] ?? null) === 'SELLER_SKU')['value_name'] ?? null;

                return [
                    'ml_item_id' => $item['id'],
                    'sku' => $sku,
                    'titulo' => $item['title'] ?? null,
                ];
            })
            ->all();
    }

    /**
     * @param  array{ml_item_id: string, sku: ?string, titulo: ?string}  $detalle
     * @return array{referencia: string, motivo: string, detalle?: string}|null null = vinculado con éxito.
     */
    private function procesar(array $detalle, ?User $usuario): ?array
    {
        $sku = trim((string) $detalle['sku']);

        if ($sku === '') {
            return ['referencia' => $detalle['ml_item_id'], 'motivo' => 'sin_sku'];
        }

        // FR-002/FR-004: sin excluir productos inactivos. Un SKU no numérico
        // castea a 0, que nunca matchea un id real — mismo motivo que
        // cualquier SKU sin coincidencia.
        $producto = Producto::find((int) $sku);

        if (! $producto) {
            return ['referencia' => $detalle['ml_item_id'], 'motivo' => 'producto_no_encontrado'];
        }

        // FR-001/FR-003: un producto puede tener varias publicaciones vinculadas
        // simultáneamente, no se rechaza por "ya_vinculado".
        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => $detalle['ml_item_id'],
            'producto_id' => $producto->id,
            'titulo_ml' => $detalle['titulo'],
            'vinculada_por' => $usuario?->id,
        ]);

        return null;
    }
}
