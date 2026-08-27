<?php

namespace App\Services\MercadoLibre;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use Illuminate\Support\Facades\DB;

/**
 * Compara lo que está publicado en Mercado Libre contra lo que el CRM cree que está publicado
 * (spec 084, US3).
 *
 * Es la red que faltó el 25/08/2026: 18 publicaciones estuvieron 30 horas un 31% por debajo de su
 * precio y **nada avisó**; se descubrió porque el usuario preguntó.
 *
 * **Cada publicación se compara contra la lista que le corresponde por su tipo**, delegando en
 * `SincronizadorPrecios::resolverListaPrecio()`. Esto no es un detalle: durante el diagnóstico del
 * 26/08 se comparó todo contra la lista general y las 30 publicaciones Premium aparecieron como
 * desfasadas. Un panel que muestra 30 falsos positivos todos los días se vuelve ruido, la gente lo
 * ignora, y el día que aparezca uno verdadero nadie lo va a ver. Está prohibido reimplementar la
 * resolución acá: una segunda definición que se desactualice reproduce la causa raíz del incidente.
 *
 * **Es de sólo lectura hacia Mercado Libre**: informa, no corrige (FR-027). Lo único que escribe es
 * `precio_publicado` en la base, y sólo cuando se le pide — que es como se hace el backfill previo
 * a activar el corte (research.md Decisión 5).
 */
class ChequeoPreciosPublicados
{
    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
        private readonly SincronizadorPrecios $sincronizador,
    ) {}

    /**
     * @return array{corrida_en: string, resumen: array, diferencias: array, retenidas: array, advertencias: array}
     */
    public function ejecutar(bool $refrescarPublicado = false): array
    {
        $configuracion = MercadoLibreConfiguracion::actual();

        $verificadas = 0;
        $coinciden = 0;
        $diferencias = [];
        $retenidas = [];
        $noVerificables = [];
        $premiumSinPrecio = [];
        $sinTipo = [];

        foreach (MercadoLibrePublicacionProducto::with(['producto', 'retencionAbierta'])->get() as $vinculo) {
            if (! $vinculo->producto) {
                continue;
            }

            if ($vinculo->listing_type_id === null) {
                $sinTipo[] = $this->fila($vinculo);
            }

            if ($vinculo->esPremium() && $configuracion->lista_precio_id_premium
                && ! $this->tienePrecioEn($vinculo->producto_id, (int) $configuracion->lista_precio_id_premium)) {
                $premiumSinPrecio[] = $this->fila($vinculo);
            }

            $respuesta = $this->cliente->obtener('verificar_precio', "/items/{$vinculo->ml_item_id}?attributes=id,price,status");

            if ($respuesta->fallo() || ! isset($respuesta->datos['price'])) {
                // Nunca se cuenta como coincidente (FR-024): "no pude verificar" y "está bien" son
                // cosas distintas, y confundirlas es cómo un panel verde esconde un problema.
                $noVerificables[] = $this->fila($vinculo) + [
                    'error' => $respuesta->mensajeError ?? 'Mercado Libre no devolvió el precio.',
                ];

                continue;
            }

            $enMl = round((float) $respuesta->datos['price'], 2);

            if ($refrescarPublicado) {
                $vinculo->update(['precio_publicado' => $enMl, 'precio_publicado_en' => now()]);
            }

            $verificadas++;

            $listaId = $this->sincronizador->resolverListaPrecio($vinculo, $configuracion);
            $enCrm = $listaId ? $this->precioEn($vinculo->producto_id, $listaId) : null;

            $fila = $this->fila($vinculo) + [
                'precio_publicado' => $enMl,
                'precio_crm' => $enCrm,
                'diferencia' => $enCrm === null ? null : round($enCrm - $enMl, 2),
                'estado_ml' => $respuesta->datos['status'] ?? null,
            ];

            // Una retenida difiere a propósito: es el sistema haciendo su trabajo, no un problema
            // (FR-023). Mezclarlas haría que el panel muestre como falla algo que salió bien.
            if ($vinculo->retencionAbierta) {
                $retenidas[] = $fila;

                continue;
            }

            if ($enCrm !== null && abs($enCrm - $enMl) < 0.01) {
                $coinciden++;

                continue;
            }

            $diferencias[] = $fila;
        }

        return [
            'corrida_en' => now()->toIso8601String(),
            'resumen' => [
                'verificadas' => $verificadas,
                'coinciden' => $coinciden,
                'difieren' => count($diferencias),
                'retenidas' => count($retenidas),
                'no_verificables' => count($noVerificables),
            ],
            'diferencias' => $diferencias,
            'retenidas' => $retenidas,
            'no_verificables' => $noVerificables,
            'advertencias' => [
                'premium_sin_precio_en_su_lista' => $premiumSinPrecio,
                'sin_tipo_de_publicacion' => $sinTipo,
            ],
        ];
    }

    private function fila(MercadoLibrePublicacionProducto $vinculo): array
    {
        return [
            'ml_item_id' => $vinculo->ml_item_id,
            'producto_id' => $vinculo->producto_id,
            'producto' => trim(($vinculo->producto?->codigo ?? '').' — '.($vinculo->producto?->nombre ?? ''), ' —'),
            'tipo_publicacion' => $vinculo->listing_type_id === null
                ? 'sin determinar'
                : ($vinculo->esPremium() ? 'Premium' : 'Clásica'),
        ];
    }

    private function precioEn(int $productoId, int $listaId): ?float
    {
        $precio = DB::table('precios_producto')
            ->where('producto_id', $productoId)
            ->where('lista_precio_id', $listaId)
            ->value('precio');

        return $precio === null ? null : round((float) $precio, 2);
    }

    private function tienePrecioEn(int $productoId, int $listaId): bool
    {
        return DB::table('precios_producto')
            ->where('producto_id', $productoId)
            ->where('lista_precio_id', $listaId)
            ->exists();
    }
}
