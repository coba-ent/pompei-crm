<?php

namespace App\Services\Tiendanube;

use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Importación masiva de vinculaciones variante↔producto de Tiendanube (spec
 * 021 reemplazo, US2) a partir del archivo de productos que la propia
 * Tiendanube exporta: el SKU del archivo resuelve el producto del CRM por
 * `codigo` (research.md R4), el "Identificador de URL" resuelve el producto
 * real de Tiendanube consultando el catálogo en vivo (research.md R5).
 */
class ImportadorVinculaciones
{
    public function __construct(private ClienteTiendanube $cliente) {}

    /**
     * @return array{total: int, vinculadas: int, fallidas: int, detalle_fallidas: array<int, array{referencia: string, motivo: string, detalle?: string}>}
     */
    public function importar(string $rutaArchivo, ?User $usuario): array
    {
        $filas = (Excel::toArray(null, $rutaArchivo))[0] ?? [];
        $encabezados = array_map(fn ($v) => trim((string) $v), $filas[0] ?? []);
        $indiceSku = array_search('SKU', $encabezados, true);
        $indiceSlug = array_search('Identificador de URL', $encabezados, true);
        $filasDatos = array_slice($filas, 1);

        $productosVinculados = array_fill_keys(TiendanubeVarianteProducto::pluck('producto_id')->all(), true);
        $variantesVinculadas = array_fill_keys(TiendanubeVarianteProducto::pluck('variant_id')->all(), true);

        // Cachear el catálogo en memoria: una sola consulta paginada por
        // importación, no una por fila (research.md R5) — se carga recién en
        // la primera fila que la necesita, nunca si todas fallan antes.
        $catalogo = null;

        $vinculadas = 0;
        $procesadas = 0;
        $detalleFallidas = [];

        foreach ($filasDatos as $celdas) {
            if ($this->filaVacia($celdas)) {
                continue;
            }

            $procesadas++;

            $sku = $indiceSku !== false ? trim((string) ($celdas[$indiceSku] ?? '')) : '';
            $slug = $indiceSlug !== false ? trim((string) ($celdas[$indiceSlug] ?? '')) : '';

            $producto = $this->resolverProducto($sku);

            if (! $producto) {
                $detalleFallidas[] = ['referencia' => $sku !== '' ? $sku : $slug, 'motivo' => 'producto_no_encontrado'];

                continue;
            }

            $catalogo ??= $this->cargarCatalogo();
            $entrada = $catalogo[$slug] ?? null;

            if (! $entrada) {
                $detalleFallidas[] = ['referencia' => $sku, 'motivo' => 'tiendanube_no_encontrado'];

                continue;
            }

            if (isset($variantesVinculadas[$entrada['variant_id']])) {
                $detalleFallidas[] = ['referencia' => $sku, 'motivo' => 'ya_vinculado', 'detalle' => 'sku'];

                continue;
            }

            if (isset($productosVinculados[$producto->id])) {
                $detalleFallidas[] = ['referencia' => $sku, 'motivo' => 'ya_vinculado', 'detalle' => 'producto'];

                continue;
            }

            TiendanubeVarianteProducto::create([
                'variant_id' => $entrada['variant_id'],
                'tn_product_id' => $entrada['product_id'],
                'producto_id' => $producto->id,
                'vinculada_por' => $usuario?->id,
            ]);

            $variantesVinculadas[$entrada['variant_id']] = true;
            $productosVinculados[$producto->id] = true;
            $vinculadas++;
        }

        return [
            'total' => $procesadas,
            'vinculadas' => $vinculadas,
            'fallidas' => count($detalleFallidas),
            'detalle_fallidas' => $detalleFallidas,
        ];
    }

    /** research.md R4: exacto primero, luego el número inicial del código (separador espacio, plan.md). */
    private function resolverProducto(string $sku): ?Producto
    {
        if ($sku === '') {
            return null;
        }

        return Producto::where('codigo', $sku)->first()
            ?? Producto::where('codigo', 'like', $sku.' %')->first();
    }

    private function filaVacia(array $celdas): bool
    {
        foreach ($celdas as $valor) {
            if (trim((string) $valor) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array{product_id: string, variant_id: int}> índice = slug de `product_url`.
     */
    private function cargarCatalogo(): array
    {
        $catalogo = [];
        $pagina = 1;

        while (true) {
            $respuesta = $this->cliente->leer('list_products', [
                'page_size' => 50, 'page' => $pagina, 'fields_needed' => ['id', 'product_url'],
            ]);

            // CHK004: un fallo de conexión ya pasó por los reintentos de
            // ClienteTiendanube — se corta acá y las filas pendientes quedan
            // "tiendanube_no_encontrado" (catálogo parcial o vacío), sin lógica
            // de reintento propia.
            if ($respuesta->fallo()) {
                break;
            }

            foreach (($respuesta->datos['products'] ?? []) as $producto) {
                $slug = $this->slugDeUrl((string) ($producto['product_url'] ?? ''));
                $variantId = $producto['variants'][0]['id'] ?? null;

                if ($slug === '' || $variantId === null) {
                    continue;
                }

                $catalogo[$slug] = ['product_id' => (string) ($producto['id'] ?? ''), 'variant_id' => (int) $variantId];
            }

            $totalPaginas = (int) ($respuesta->datos['pagination']['total_pages'] ?? $pagina);

            if ($pagina >= $totalPaginas) {
                break;
            }

            $pagina++;
        }

        return $catalogo;
    }

    private function slugDeUrl(string $url): string
    {
        $partes = array_values(array_filter(explode('/', rtrim($url, '/'))));

        return (string) (end($partes) ?: '');
    }
}
