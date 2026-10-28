<?php

namespace App\Services\MercadoLibre;

use App\Models\Integraciones\MercadoLibreCambioTipoPublicacion as Cambio;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto as Vinculo;
use App\Models\Integraciones\MercadoLibreRetencionPrecio;
use App\Models\ListaPrecio;
use InvalidArgumentException;

/**
 * Un cambio de tipo Clásica ↔ Premium hecho en Mercado Libre y detectado por el CRM (spec 116).
 *
 * Un cambio de tipo es siempre un cambio de precio (§3.2.ter.quinquies): si alguien pasa una Premium a
 * Clásica desde ML, ML le deja el precio Premium; al revés, le deja el de Clásica cobrando la comisión
 * de Premium. Acá se consolida el tipo y se publica el precio de la lista que le corresponde ahora.
 *
 * El precio sale con `omitirCorte` (tercera excepción al corte de spec 084, research R3): el cambio de
 * tipo en ML ya es una decisión humana, y Premium → Clásica baja siempre 15-31%, así que el corte lo
 * retendría casi siempre. La excepción vale sólo acá y sólo con las precondiciones de abajo.
 *
 * Precondiciones, que asegura el llamador: sin cortes de escritura y con el candado
 * `SincronizadorPrecios::LOCK_KEY` tomado. Si no se pudiera enviar, el llamador no debe llamar: el tipo
 * no se consolida, para que la próxima revisión lo vuelva a detectar (FR-008).
 */
class ProcesadorCambioTipoDetectado
{
    public function __construct(
        private readonly SincronizadorPrecios $sincronizadorPrecios,
    ) {}

    /**
     * `$tipoVigente` es el que informó ML en esta misma corrida. `$usuarioId` null = lo detectó la
     * revisión de tipos; con valor = se detectó al confirmar un cambio desde el CRM (FR-011a).
     *
     * @return array{resultado: string, mensaje: string, enviado: bool}
     */
    public function procesar(Vinculo $vinculo, string $tipoVigente, ?int $usuarioId = null): array
    {
        $tipoAnterior = $vinculo->listing_type_id;

        if (! self::esCambioDetectado($tipoAnterior, $tipoVigente)) {
            throw new InvalidArgumentException("No es un cambio Clásica ↔ Premium: {$tipoAnterior} → {$tipoVigente}.");
        }

        $precioAnterior = $vinculo->precio_publicado === null ? null : (float) $vinculo->precio_publicado;
        $vinculo->update(['listing_type_id' => $tipoVigente, 'listing_type_sincronizado_en' => now()]);

        $configuracion = MercadoLibreConfiguracion::actual();
        $listaId = $this->sincronizadorPrecios->resolverListaPrecio($vinculo, $configuracion);
        $nombreLista = $listaId ? (ListaPrecio::whereKey($listaId)->value('nombre') ?? "#{$listaId}") : null;
        $precio = $listaId && $vinculo->producto
            ? $vinculo->producto->precios()->where('lista_precio_id', $listaId)->value('precio')
            : null;

        $etiqueta = Vinculo::etiquetaTipo($tipoVigente);
        $prefijo = "Pasada a {$etiqueta} desde Mercado Libre";
        $respaldo = $tipoVigente === Vinculo::TIPO_PREMIUM && $listaId !== null && (int) $listaId !== (int) $configuracion->lista_precio_id_premium
            ? ' (sin precio en la lista Premium: se usó la lista general)'
            : '';

        $registrar = fn (string $resultado, string $mensaje, ?float $precioNuevo) => $this->registrar(
            $vinculo, $tipoAnterior, $tipoVigente, $precioAnterior, $precioNuevo, $listaId, $resultado, $mensaje, $usuarioId
        );

        if ($precio === null || (float) $precio <= 0) {
            $mensaje = "{$prefijo}; sin precio para publicar".($nombreLista ? " en la lista {$nombreLista}" : '').'.';
            $registrar(Cambio::RESULTADO_SIN_CAMBIOS, $mensaje, null);

            return ['resultado' => Cambio::RESULTADO_SIN_CAMBIOS, 'mensaje' => $mensaje, 'enviado' => false];
        }

        $precio = (float) $precio;

        if ($precioAnterior !== null && abs($precioAnterior - $precio) < 0.005) {
            MercadoLibreRetencionPrecio::where('ml_publicacion_producto_id', $vinculo->getKey())
                ->abiertas()
                ->update(['estado' => MercadoLibreRetencionPrecio::ESTADO_REEMPLAZADA, 'resuelta_en' => now()]);
            $mensaje = "{$prefijo}; ya tenía el precio de la lista {$nombreLista}.";
            $registrar(Cambio::RESULTADO_SIN_CAMBIOS, $mensaje, $precio);

            return ['resultado' => Cambio::RESULTADO_SIN_CAMBIOS, 'mensaje' => $mensaje, 'enviado' => false];
        }

        if ($this->sincronizadorPrecios->enviarUno($vinculo, $precio, omitirCorte: true)) {
            $mensaje = "{$prefijo}; se publicó el precio de la lista {$nombreLista}{$respaldo}.";
            $registrar(Cambio::RESULTADO_CAMBIADO, $mensaje, $precio);

            return ['resultado' => Cambio::RESULTADO_CAMBIADO, 'mensaje' => $mensaje, 'enviado' => true];
        }

        // `enviarUno()` ya dejó `precio_pendiente` y el error: el tipo queda, porque es el real (FR-009).
        $mensaje = "{$prefijo}; el precio quedó pendiente: ".($vinculo->fresh()->precio_error ?? 'Mercado Libre rechazó el precio.');
        $registrar(Cambio::RESULTADO_CAMBIADO_PRECIO_PENDIENTE, $mensaje, $precio);

        return ['resultado' => Cambio::RESULTADO_CAMBIADO_PRECIO_PENDIENTE, 'mensaje' => $mensaje, 'enviado' => false];
    }

    /** Clásica ↔ Premium con tipo guardado conocido: la primera clasificación y los demás tipos no cuentan. */
    public static function esCambioDetectado(?string $tipoGuardado, ?string $tipoVigente): bool
    {
        return $tipoGuardado !== $tipoVigente
            && in_array($tipoGuardado, Vinculo::TIPOS_SOPORTADOS, true)
            && in_array($tipoVigente, Vinculo::TIPOS_SOPORTADOS, true);
    }

    private function registrar(Vinculo $vinculo, string $tipoAnterior, string $tipoVigente, ?float $precioAnterior, ?float $precioNuevo, ?int $listaId, string $resultado, string $mensaje, ?int $usuarioId): void
    {
        Cambio::create([
            'ml_publicacion_producto_id' => $vinculo->getKey(),
            'ml_item_id' => $vinculo->ml_item_id,
            'producto_id' => $vinculo->producto_id,
            'tipo_anterior' => $tipoAnterior,
            'tipo_destino' => $tipoVigente,
            'precio_anterior' => $precioAnterior,
            'precio_nuevo' => $precioNuevo,
            'lista_precio_id' => $listaId,
            'resultado' => $resultado,
            'origen' => Cambio::ORIGEN_MERCADOLIBRE,
            'mensaje' => $mensaje,
            'usuario_id' => $usuarioId,
        ]);
    }
}
