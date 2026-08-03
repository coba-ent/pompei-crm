<?php

namespace App\Services\Tiendanube;

use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\User;
use App\Services\Tiendanube\Excepciones\VinculacionAutomaticaFallidaException;

/**
 * Reemplaza tanto el selector manual (fuente: `tn_orden_items`) como la
 * importación por Excel (fuente: slug) por un único mecanismo 100%
 * automático (spec 024) que resuelve el SKU vigente contra el catálogo REST
 * en vivo del vendedor conectado — deja de depender de que la variante haya
 * vendido alguna vez. A diferencia de `MercadoLibre\VinculadorAutomatico`
 * (spec 023), el SKU viene directo en el listado paginado (sin multiget,
 * research.md R2) y no se excluyen productos con variantes múltiples: cada
 * variante ya es su propia unidad de vinculación (research.md R7).
 */
class VinculadorAutomatico
{
    private const PER_PAGE = 50;

    public function __construct(private ClienteTiendanubeRest $cliente) {}

    /**
     * @return array{total: int, vinculadas: int, fallidas: int, detalle_fallidas: array<int, array{referencia: string, motivo: string, detalle?: string}>}
     */
    public function ejecutar(?User $usuario): array
    {
        $variantesVinculadas = array_fill_keys(
            TiendanubeVarianteProducto::pluck('variant_id')->all(),
            true
        );

        $total = 0;
        $vinculadas = 0;
        $detalleFallidas = [];

        foreach ($this->recorrerCatalogo() as $producto) {
            if (($producto['status'] ?? null) === 'closed') {
                continue;
            }

            foreach ($producto['variants'] ?? [] as $variante) {
                $variantId = $variante['id'] ?? null;

                if ($variantId === null || isset($variantesVinculadas[$variantId])) {
                    continue;
                }

                $total++;
                $resultado = $this->procesar($producto, $variante, $usuario);

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
     * Recorre `GET /products` paginado (`page`/`per_page`, research.md R1)
     * hasta que una página devuelve menos de `per_page` resultados. Aborta la
     * corrida completa si el catálogo falla a mitad de camino (spec.md
     * Assumptions).
     *
     * @return array<int, array>
     */
    private function recorrerCatalogo(): array
    {
        $productos = [];
        $pagina = 1;

        do {
            $respuesta = $this->cliente->leer('products', ['page' => $pagina, 'per_page' => self::PER_PAGE]);

            if ($respuesta->fallo()) {
                throw new VinculacionAutomaticaFallidaException(
                    "No se pudo completar la vinculación automática: {$respuesta->mensajeError}."
                );
            }

            // Verificado empíricamente contra la cuenta real (spec 024): `GET /products`
            // devuelve un array JSON plano en la raíz (`[{...}, {...}]`), no un objeto
            // envuelto en `{"products": [...]}` como asumía la primera versión del
            // contrato — de ahí que `array_is_list` alcance para distinguir ambos casos
            // sin romper si Tiendanube cambia de formato más adelante.
            $pagina_productos = array_is_list($respuesta->datos) ? $respuesta->datos : ($respuesta->datos['products'] ?? []);
            array_push($productos, ...$pagina_productos);

            $cantidad = count($pagina_productos);
            $pagina++;
        } while ($cantidad === self::PER_PAGE);

        return $productos;
    }

    /**
     * @return array{referencia: string, motivo: string, detalle?: string}|null null = vinculado con éxito.
     */
    private function procesar(array $producto, array $variante, ?User $usuario): ?array
    {
        $variantId = (string) $variante['id'];
        $sku = trim((string) ($variante['sku'] ?? ''));

        if ($sku === '') {
            return ['referencia' => $variantId, 'motivo' => 'sin_sku'];
        }

        // FR-008: sin excluir productos inactivos. `(int)` sobre un string en PHP
        // toma sólo los dígitos iniciales hasta el primer carácter no numérico
        // (incluido un espacio) — a propósito: varios SKU reales de Tiendanube
        // traen el id seguido de texto libre (ej. "26168 SKU 7024 ABAB-9006C",
        // "41036 CAJ303060"), y sólo el número antes del primer espacio
        // corresponde al id del producto del CRM. Un SKU sin dígitos iniciales
        // castea a 0, que nunca matchea un id real — mismo motivo que cualquier
        // SKU sin coincidencia.
        $productoCrm = Producto::find((int) $sku);

        if (! $productoCrm) {
            return ['referencia' => $variantId, 'motivo' => 'producto_no_encontrado'];
        }

        // FR-011/FR-013: un producto puede tener varias variantes vinculadas
        // simultáneamente, no se rechaza por "ya_vinculado".
        TiendanubeVarianteProducto::create([
            'variant_id' => $variante['id'],
            'tn_product_id' => $producto['id'],
            'producto_id' => $productoCrm->id,
            'vinculada_por' => $usuario?->id,
        ]);

        return null;
    }
}
