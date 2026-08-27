<?php

namespace App\Services\MercadoLibre;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use Illuminate\Support\Facades\DB;

/**
 * Qué pasaría si se cambiara la Lista de Precios configurada (spec 084, US2).
 *
 * Hasta esta spec, mover ese select y guardar republicaba las 270 publicaciones **en el acto**:
 * sin confirmación, sin previa y sin deshacer. Un clic equivocado —elegir "Mayorista/obras" en
 * lugar de "ML"— bajaba el precio de todo el catálogo publicado de una vez. Era la vía de daño más
 * grande que quedaba abierta después del incidente del 25/08.
 *
 * **Se resuelve contra `precio_publicado`, sin llamar a la API** (research.md Decisión 7): la
 * previa tiene que aparecer al instante cuando la persona aprieta Guardar, y 270 llamadas HTTP
 * antes de mostrar un diálogo son medio minuto de espera que se lee como "se colgó". El dato puede
 * tener hasta 24 horas; para un conteo orientativo alcanza, y la decisión fina la toma el corte
 * publicación por publicación en el envío real.
 */
class PrevisualizadorCambioLista
{
    public function __construct(private readonly SincronizadorPrecios $sincronizador) {}

    /**
     * @return array{publicaciones_afectadas: int, suben: int, bajan: int, sin_cambio: int, quedarian_retenidas: int, sin_precio_en_la_lista: int, caida_maxima: ?array}
     */
    public function calcular(int $listaPrecioId, string $rol = 'general', ?MercadoLibreConfiguracion $configuracion = null): array
    {
        $configuracion = $configuracion ?? MercadoLibreConfiguracion::actual();
        $umbral = (float) $configuracion->umbral_caida_precio_pct;

        $afectadas = 0;
        $suben = 0;
        $bajan = 0;
        $sinCambio = 0;
        $retenidas = 0;
        $sinPrecio = 0;
        $peor = null;

        // Se simula sobre la configuración PROPUESTA, no sobre la vigente. Sin aplicarle la lista
        // nueva al clon, `resolverListaPrecio()` seguiría devolviendo la lista actual y ninguna
        // publicación coincidiría: el impacto daría cero y el diálogo diría que no pasa nada.
        $propuesta = clone $configuracion;
        $propuesta->{$rol === 'premium' ? 'lista_precio_id_premium' : 'lista_precio_id'} = $listaPrecioId;

        foreach (MercadoLibrePublicacionProducto::with('producto')->get() as $vinculo) {
            if (! $vinculo->producto) {
                continue;
            }

            if ($this->sincronizador->resolverListaPrecio($vinculo, $propuesta) !== $listaPrecioId) {
                continue;
            }

            $afectadas++;

            $nuevo = DB::table('precios_producto')
                ->where('producto_id', $vinculo->producto_id)
                ->where('lista_precio_id', $listaPrecioId)
                ->value('precio');

            if ($nuevo === null) {
                // No cambiaría de precio: se quedaría con el que ya tiene publicado. Un número
                // alto acá significa que la lista elegida está incompleta, y es lo primero que hay
                // que mirar antes de confirmar.
                $sinPrecio++;

                continue;
            }

            $nuevo = (float) $nuevo;
            $publicado = $vinculo->precio_publicado === null ? null : (float) $vinculo->precio_publicado;

            if ($publicado === null) {
                $retenidas++;

                continue;
            }

            if (abs($nuevo - $publicado) < 0.005) {
                $sinCambio++;

                continue;
            }

            if ($nuevo > $publicado) {
                $suben++;

                continue;
            }

            $bajan++;
            $caida = (($publicado - $nuevo) / $publicado) * 100;

            if ($caida > $umbral) {
                $retenidas++;
            }

            if ($peor === null || $caida > $peor['pct']) {
                $peor = [
                    'pct' => round($caida, 2),
                    'ml_item_id' => $vinculo->ml_item_id,
                    'de' => $publicado,
                    'a' => $nuevo,
                ];
            }
        }

        return [
            'publicaciones_afectadas' => $afectadas,
            'suben' => $suben,
            'bajan' => $bajan,
            'sin_cambio' => $sinCambio,
            'quedarian_retenidas' => $retenidas,
            'sin_precio_en_la_lista' => $sinPrecio,
            'caida_maxima' => $peor,
        ];
    }
}
