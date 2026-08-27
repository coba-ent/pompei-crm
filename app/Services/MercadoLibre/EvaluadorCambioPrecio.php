<?php

namespace App\Services\MercadoLibre;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\MercadoLibreRetencionPrecio;

/**
 * El corte de seguridad de precios (spec 084, US1).
 *
 * Decide si un precio propuesto puede salir hacia Mercado Libre o hay que frenarlo para que una
 * persona lo mire. Nace de dos incidentes reales de agosto de 2026 en los que el CRM publicó —o
 * estuvo a punto de publicar— precios muy por debajo de los reales sin que nada avisara.
 *
 * **No tiene efectos secundarios**: no escribe en la base, no llama a la API, no registra nada.
 * Sólo decide. Eso es lo que permite testear a fondo cada regla sin montar medio sistema, y es
 * deliberado: la lógica que protege el dinero tiene que ser trivial de verificar.
 *
 * Las cuatro reglas, en orden de evaluación:
 *
 * 1. Precio propuesto ≤ 0 → **retiene** siempre, sea cual sea el umbral.
 * 2. Sin precio publicado conocido → **retiene**, aunque el precio nuevo sea más alto. Sin saber
 *    qué hay publicado no se puede afirmar que no se está bajando (research.md Decisión 1).
 * 3. El precio sube o no cambia → **publica**. El corte es sólo para bajadas (Decisión 6): un
 *    precio de más no hace perder dinero en una venta, y retener subidas convertiría cada
 *    actualización de lista en una cola de aprobaciones.
 * 4. Baja: se compara la caída porcentual contra el umbral. **Igual al umbral pasa; se retiene lo
 *    mayor.** El borde es arbitrario pero tiene que estar escrito para que el test lo fije.
 */
class EvaluadorCambioPrecio
{
    /**
     * @return array{publicar: bool, motivo: ?string, caida_pct: ?float, precio_publicado: ?float, umbral_pct: float}
     */
    public function evaluar(
        MercadoLibrePublicacionProducto $vinculo,
        float $precioPropuesto,
        ?MercadoLibreConfiguracion $configuracion = null,
    ): array {
        $configuracion ??= MercadoLibreConfiguracion::actual();
        $umbral = (float) $configuracion->umbral_caida_precio_pct;

        $publicado = $vinculo->precio_publicado === null ? null : (float) $vinculo->precio_publicado;

        $decision = fn (bool $publicar, ?string $motivo, ?float $caida = null) => [
            'publicar' => $publicar,
            'motivo' => $motivo,
            'caida_pct' => $caida,
            'precio_publicado' => $publicado,
            'umbral_pct' => $umbral,
        ];

        // Apagado hasta que `precio_publicado` esté poblado en todas las publicaciones (Decisión 5).
        // Sin esta guarda, el día del deploy el corte retendría todo, porque todavía no conoce
        // ningún precio publicado.
        if (! $configuracion->corte_precios_activo) {
            return $decision(true, null);
        }

        if ($precioPropuesto <= 0) {
            return $decision(false, MercadoLibreRetencionPrecio::MOTIVO_PRECIO_INVALIDO);
        }

        // Un umbral de 100 no apaga el corte: las dos guardas de arriba y ésta siguen valiendo.
        if ($publicado === null || $publicado <= 0) {
            return $decision(false, MercadoLibreRetencionPrecio::MOTIVO_SIN_REFERENCIA);
        }

        if ($precioPropuesto >= $publicado) {
            return $decision(true, null, 0.0);
        }

        $caida = (($publicado - $precioPropuesto) / $publicado) * 100;

        // La comparación va sobre el valor SIN redondear y el registro guarda el redondeado. Con
        // umbral 0, una caída de $100.000 a $99.999 es 0,001%: redondeada a dos decimales da 0,00 y
        // se publicaría, que es justo lo que un umbral en 0 quiere impedir.
        // `>` y no `>=`: una caída exactamente igual al umbral se publica.
        if ($caida > $umbral) {
            return $decision(false, MercadoLibreRetencionPrecio::MOTIVO_SUPERA_UMBRAL, round($caida, 2));
        }

        return $decision(true, null, round($caida, 2));
    }
}
