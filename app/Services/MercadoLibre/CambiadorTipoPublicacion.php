<?php

namespace App\Services\MercadoLibre;

use App\Models\Integraciones\MercadoLibreCambioTipoPublicacion as Cambio;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto as Vinculo;
use App\Models\Integraciones\MercadoLibreRetencionPrecio;
use App\Models\ListaPrecio;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Cambio de tipo de publicación Clásica ↔ Premium (spec 115).
 *
 * Un cambio de tipo es en realidad **tipo + precio**: Premium cotiza por su propia lista (spec 050).
 * La regla que no se negocia (FR-008) es que la publicación nunca quede, ni un instante, con un
 * precio menor al de su tipo — el incidente del 25/08/2026 fue exactamente eso. Por eso el orden:
 *
 *   → Premium: subir el precio  → cambiar el tipo  (si el tipo falla, se restituye el precio)
 *   → Clásica: cambiar el tipo  → bajar el precio  (si el precio falla, cobra de más, no de menos)
 *
 * El precio sale siempre por `SincronizadorPrecios::enviarUno()`, el único punto que hace el PUT,
 * con `omitirCorte` porque una persona confirmó los dos importes (FR-009, research R3).
 */
class CambiadorTipoPublicacion
{
    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
        private readonly SincronizadorPrecios $sincronizadorPrecios,
    ) {}

    /**
     * Arma la confirmación (contracts §1). Consulta a ML el tipo vigente y los tipos disponibles;
     * lo único que escribe es el tipo del vínculo cuando el de ML difiere del guardado.
     *
     * `$destino` null sólo para vínculos sin clasificar: se propone el opuesto del tipo real.
     * Con `ok = false` hubo un error de comunicación con ML (no un bloqueo de negocio).
     */
    public function previsualizar(Vinculo $vinculo, ?string $destino = null): array
    {
        $vinculo->loadMissing('producto:id,nombre,codigo');
        $configuracion = MercadoLibreConfiguracion::actual();

        $base = [
            'ok' => true,
            'puede_confirmar' => false,
            'motivo_bloqueo' => null,
            'vinculo' => $this->datosVinculo($vinculo),
            'tipo_actual' => $vinculo->listing_type_id,
            'tipo_actual_etiqueta' => $vinculo->tipo_etiqueta,
            'tipo_destino' => $destino,
            'tipo_destino_etiqueta' => $destino ? Vinculo::etiquetaTipo($destino) : null,
            'precio_actual' => $vinculo->precio_publicado === null ? null : (float) $vinculo->precio_publicado,
            'precio_destino' => null,
            'lista_destino' => null,
            'sin_cambios' => false,
            'aviso_comision' => $destino === Vinculo::TIPO_PREMIUM,
        ];

        if ($motivo = $this->motivoBloqueoLocal($vinculo, $destino, $configuracion, $base)) {
            return [...$base, 'motivo_bloqueo' => $motivo];
        }

        // Tipo vigente en ML (FR-006): el guardado puede estar desactualizado si alguien lo cambió
        // desde Mercado Libre, y para un vínculo sin clasificar es la única forma de saberlo.
        $item = $this->cliente->obtener('consultar_tipo_publicacion', "/items/{$vinculo->ml_item_id}");

        if ($item->fallo()) {
            return ['ok' => false, 'mensaje' => $item->mensajeError ?? 'No se pudo consultar la publicación en Mercado Libre.'];
        }

        $tipoVigente = $item->datos['listing_type_id'] ?? null;

        if ($tipoVigente && $tipoVigente !== $vinculo->listing_type_id) {
            $vinculo->update(['listing_type_id' => $tipoVigente, 'listing_type_sincronizado_en' => now()]);
        }

        $base['tipo_actual'] = $tipoVigente;
        $base['tipo_actual_etiqueta'] = Vinculo::etiquetaTipo($tipoVigente);

        if (! in_array($tipoVigente, Vinculo::TIPOS_SOPORTADOS, true)) {
            return [...$base, 'motivo_bloqueo' => 'La publicación es de tipo "'.Vinculo::etiquetaTipo($tipoVigente).'": sólo se puede cambiar entre Clásica y Premium.'];
        }

        if ($destino === null) {
            $destino = $tipoVigente === Vinculo::TIPO_PREMIUM ? Vinculo::TIPO_CLASICA : Vinculo::TIPO_PREMIUM;
            $base['tipo_destino'] = $destino;
            $base['tipo_destino_etiqueta'] = Vinculo::etiquetaTipo($destino);
            $base['aviso_comision'] = $destino === Vinculo::TIPO_PREMIUM;

            if ($motivo = $this->motivoBloqueoLocal($vinculo, $destino, $configuracion, $base)) {
                return [...$base, 'motivo_bloqueo' => $motivo];
            }
        }

        if ($tipoVigente === $destino) {
            return [...$base, 'sin_cambios' => true];
        }

        $recurso = $destino === Vinculo::TIPO_PREMIUM ? 'available_upgrades' : 'available_downgrades';
        $disponibles = $this->cliente->obtener('consultar_tipo_publicacion', "/items/{$vinculo->ml_item_id}/{$recurso}");

        if ($disponibles->fallo()) {
            return ['ok' => false, 'mensaje' => $disponibles->mensajeError ?? 'No se pudo consultar a Mercado Libre qué tipos admite la publicación.'];
        }

        if (! in_array($destino, $this->idsDisponibles($disponibles->datos), true)) {
            return [...$base, 'motivo_bloqueo' => 'Mercado Libre no ofrece el tipo '.Vinculo::etiquetaTipo($destino).' para esta publicación.'];
        }

        return [...$base, 'puede_confirmar' => true];
    }

    /**
     * Ejecuta el cambio (contracts §2). Re-evalúa todo con `previsualizar()`: no confía en lo que
     * vio la pantalla. Registra siempre una fila de historial (FR-012).
     *
     * @return array{ok: bool, resultado: string, mensaje: string, candado?: bool}
     */
    public function ejecutar(Vinculo $vinculo, string $destino, ?int $usuarioId, ?string $loteId = null): array
    {
        $lock = Cache::lock(SincronizadorPrecios::LOCK_KEY, 60);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            $mensaje = 'Hay una sincronización de precios en curso, probá en unos minutos.';
            $this->registrar($vinculo, $destino, null, Cambio::RESULTADO_BLOQUEADO, $mensaje, $usuarioId, $loteId);

            return ['ok' => false, 'resultado' => Cambio::RESULTADO_BLOQUEADO, 'mensaje' => $mensaje, 'candado' => true];
        }

        try {
            return $this->ejecutarConCandado($vinculo, $destino, $usuarioId, $loteId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Reglas locales del lote (FR-014): lo que se puede anticipar con datos del CRM, sin ninguna
     * llamada a ML. Lo que sólo ML sabe se verifica al procesar cada publicación.
     */
    public function previsualizarLote(array $ids, string $destino): array
    {
        $configuracion = MercadoLibreConfiguracion::actual();
        $aCambiar = [];
        $omitidas = [];

        $vinculos = Vinculo::with('producto:id,nombre,codigo')->whereIn('id', $ids)->get();

        foreach ($vinculos as $vinculo) {
            $datos = ['precio_destino' => null, 'lista_destino' => null];
            $motivo = $this->motivoBloqueoLocal($vinculo, $destino, $configuracion, $datos);

            if (! $motivo && $vinculo->listing_type_id === $destino) {
                $motivo = 'Ya es '.Vinculo::etiquetaTipo($destino).' según el CRM.';
            }

            if ($motivo) {
                $omitidas[] = ['id' => $vinculo->id, 'ml_item_id' => $vinculo->ml_item_id, 'motivo' => $motivo];

                continue;
            }

            $aCambiar[] = [
                'id' => $vinculo->id,
                'ml_item_id' => $vinculo->ml_item_id,
                'producto' => $this->etiquetaProducto($vinculo),
                'tipo_actual_etiqueta' => $vinculo->tipo_etiqueta,
                'precio_actual' => $vinculo->precio_publicado === null ? null : (float) $vinculo->precio_publicado,
                'precio_destino' => $datos['precio_destino'],
            ];
        }

        return [
            'ok' => true,
            'a_cambiar' => $aCambiar,
            'omitidas' => $omitidas,
            'puede_confirmar' => $aCambiar !== [],
            'aviso_comision' => $destino === Vinculo::TIPO_PREMIUM,
        ];
    }

    private function ejecutarConCandado(Vinculo $vinculo, string $destino, ?int $usuarioId, ?string $loteId): array
    {
        $previa = $this->previsualizar($vinculo, $destino);
        $vinculo->refresh();

        if (! $previa['ok']) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_FALLIDO, $previa['mensaje'], $usuarioId, $loteId);
        }

        $etiquetaDestino = Vinculo::etiquetaTipo($destino);

        if ($previa['sin_cambios']) {
            $this->cerrarRetencionAbierta($vinculo);

            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_SIN_CAMBIOS,
                "La publicación {$vinculo->ml_item_id} ya era {$etiquetaDestino} en Mercado Libre.", $usuarioId, $loteId);
        }

        if (! $previa['puede_confirmar']) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_BLOQUEADO, $previa['motivo_bloqueo'], $usuarioId, $loteId);
        }

        $precioAnterior = $previa['precio_actual'];
        $precioNuevo = (float) $previa['precio_destino'];
        $hayQueEnviarPrecio = $precioAnterior === null || abs($precioAnterior - $precioNuevo) >= 0.005;

        return $destino === Vinculo::TIPO_PREMIUM
            ? $this->pasarAPremium($vinculo, $previa, $precioAnterior, $precioNuevo, $hayQueEnviarPrecio, $usuarioId, $loteId)
            : $this->pasarAClasica($vinculo, $previa, $precioNuevo, $hayQueEnviarPrecio, $usuarioId, $loteId);
    }

    /** Primero el precio, después el tipo: nunca Premium con precio de Clásica (FR-008a). */
    private function pasarAPremium(Vinculo $vinculo, array $previa, ?float $precioAnterior, float $precioNuevo, bool $hayQueEnviarPrecio, ?int $usuarioId, ?string $loteId): array
    {
        $destino = Vinculo::TIPO_PREMIUM;

        if ($hayQueEnviarPrecio && ! $this->sincronizadorPrecios->enviarUno($vinculo, $precioNuevo, omitirCorte: true)) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_FALLIDO,
                'No se pudo publicar el precio Premium; el tipo no se cambió: '.($vinculo->fresh()->precio_error ?? 'Mercado Libre rechazó el precio.'),
                $usuarioId, $loteId);
        }

        $error = $this->cambiarTipo($vinculo, $destino);

        if ($error === null) {
            $this->cerrarRetencionAbierta($vinculo);

            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_CAMBIADO,
                "Publicación {$vinculo->ml_item_id} pasada a Premium a ".$this->dinero($precioNuevo).'.', $usuarioId, $loteId);
        }

        if (! $hayQueEnviarPrecio) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_FALLIDO, $error, $usuarioId, $loteId);
        }

        // El precio ya subió y el tipo no cambió: hay que devolverle a la Clásica su precio.
        if ($precioAnterior !== null && $this->sincronizadorPrecios->enviarUno($vinculo, $precioAnterior, omitirCorte: true)) {
            $mensaje = "Mercado Libre rechazó el cambio a Premium ({$error}). Se restituyó el precio anterior.";
        } else {
            $vinculo->update(['precio_pendiente' => true]);
            $mensaje = "Mercado Libre rechazó el cambio a Premium ({$error}). El precio quedó pendiente de envío por la lista general.";
        }

        return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_REVERTIDO, $mensaje, $usuarioId, $loteId);
    }

    /** Primero el tipo, después el precio: si el precio falla, cobra de más y no de menos (FR-008b). */
    private function pasarAClasica(Vinculo $vinculo, array $previa, float $precioNuevo, bool $hayQueEnviarPrecio, ?int $usuarioId, ?string $loteId): array
    {
        $destino = Vinculo::TIPO_CLASICA;

        if ($error = $this->cambiarTipo($vinculo, $destino)) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_FALLIDO, $error, $usuarioId, $loteId);
        }

        if ($hayQueEnviarPrecio && ! $this->sincronizadorPrecios->enviarUno($vinculo, $precioNuevo, omitirCorte: true)) {
            $vinculo->update(['precio_pendiente' => true]);

            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_CAMBIADO_PRECIO_PENDIENTE,
                'Pasada a Clásica; el precio quedó pendiente: '.($vinculo->fresh()->precio_error ?? 'Mercado Libre rechazó el precio.'),
                $usuarioId, $loteId);
        }

        $this->cerrarRetencionAbierta($vinculo);

        return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_CAMBIADO,
            "Publicación {$vinculo->ml_item_id} pasada a Clásica a ".$this->dinero($precioNuevo).'.', $usuarioId, $loteId);
    }

    /** POST del cambio de tipo (research R1). Devuelve el motivo del rechazo o null si ML lo aceptó. */
    private function cambiarTipo(Vinculo $vinculo, string $destino): ?string
    {
        $respuesta = $this->cliente->enviar('cambiar_tipo_publicacion', 'POST', "/items/{$vinculo->ml_item_id}/listing_type", ['id' => $destino]);

        if ($respuesta->fallo()) {
            return $respuesta->mensajeError ?? 'Mercado Libre rechazó el cambio de tipo.';
        }

        // La respuesta trae el ítem completo: si informa otro tipo, el cambio no ocurrió.
        $tipoInformado = $respuesta->datos['listing_type_id'] ?? null;

        if ($tipoInformado !== null && $tipoInformado !== $destino) {
            return 'Mercado Libre respondió con el tipo "'.Vinculo::etiquetaTipo($tipoInformado).'".';
        }

        $vinculo->update(['listing_type_id' => $destino, 'listing_type_sincronizado_en' => now()]);

        return null;
    }

    /**
     * Bloqueos que se resuelven sin preguntarle a ML. Completa en `$datos` el precio y la lista
     * destino cuando el destino ya se conoce.
     */
    private function motivoBloqueoLocal(Vinculo $vinculo, ?string $destino, MercadoLibreConfiguracion $configuracion, array &$datos): ?string
    {
        if ($motivo = $this->sincronizadorPrecios->motivoBloqueoEscritura()) {
            return $motivo;
        }

        if (! $vinculo->producto) {
            return 'La publicación no está vinculada a ningún producto del CRM.';
        }

        if ($vinculo->listing_type_id !== null && ! in_array($vinculo->listing_type_id, Vinculo::TIPOS_SOPORTADOS, true)) {
            return 'La publicación es de tipo "'.$vinculo->tipo_etiqueta.'": sólo se puede cambiar entre Clásica y Premium.';
        }

        if ($destino === null) {
            return null;
        }

        $listaId = $this->sincronizadorPrecios->listaPrecioParaTipo($destino, $configuracion);

        if (! $listaId) {
            return $destino === Vinculo::TIPO_PREMIUM
                ? 'No hay una lista de precios Premium configurada para Mercado Libre.'
                : 'No hay una lista de precios configurada para Mercado Libre.';
        }

        $lista = ListaPrecio::find($listaId, ['id', 'nombre']);
        $datos['lista_destino'] = $lista ? ['id' => $lista->id, 'nombre' => $lista->nombre] : ['id' => $listaId, 'nombre' => null];

        $precio = $vinculo->producto->precios()->where('lista_precio_id', $listaId)->value('precio');

        if ($precio === null || (float) $precio <= 0) {
            return 'El producto no tiene precio en la lista '.($lista->nombre ?? "#{$listaId}").'.';
        }

        $datos['precio_destino'] = (float) $precio;

        return null;
    }

    /** `available_upgrades|downgrades` devuelve una lista de tipos (`[{id, name, ...}]`). */
    private function idsDisponibles(array $datos): array
    {
        return collect($datos)
            ->map(fn ($tipo) => is_array($tipo) ? ($tipo['id'] ?? null) : $tipo)
            ->filter()
            ->values()
            ->all();
    }

    private function cerrarRetencionAbierta(Vinculo $vinculo): void
    {
        MercadoLibreRetencionPrecio::where('ml_publicacion_producto_id', $vinculo->getKey())
            ->abiertas()
            ->update(['estado' => MercadoLibreRetencionPrecio::ESTADO_REEMPLAZADA, 'resuelta_en' => now()]);
    }

    private function resultado(Vinculo $vinculo, string $destino, array $previa, string $resultado, string $mensaje, ?int $usuarioId, ?string $loteId): array
    {
        $this->registrar($vinculo, $destino, $previa, $resultado, $mensaje, $usuarioId, $loteId);

        return [
            'ok' => in_array($resultado, [Cambio::RESULTADO_CAMBIADO, Cambio::RESULTADO_CAMBIADO_PRECIO_PENDIENTE, Cambio::RESULTADO_SIN_CAMBIOS], true),
            'resultado' => $resultado,
            'mensaje' => $mensaje,
        ];
    }

    private function registrar(Vinculo $vinculo, string $destino, ?array $previa, string $resultado, ?string $mensaje, ?int $usuarioId, ?string $loteId): void
    {
        Cambio::create([
            'ml_publicacion_producto_id' => $vinculo->getKey(),
            'ml_item_id' => $vinculo->ml_item_id,
            'producto_id' => $vinculo->producto_id,
            'tipo_anterior' => $previa['tipo_actual'] ?? $vinculo->listing_type_id,
            'tipo_destino' => $destino,
            'precio_anterior' => $previa['precio_actual'] ?? $vinculo->precio_publicado,
            'precio_nuevo' => $previa['precio_destino'] ?? null,
            'lista_precio_id' => $previa['lista_destino']['id'] ?? null,
            'resultado' => $resultado,
            'mensaje' => $mensaje,
            'lote_id' => $loteId,
            'usuario_id' => $usuarioId,
        ]);
    }

    private function datosVinculo(Vinculo $vinculo): array
    {
        return [
            'id' => $vinculo->id,
            'ml_item_id' => $vinculo->ml_item_id,
            'titulo_ml' => $vinculo->titulo_ml,
            'producto' => $vinculo->producto
                ? ['id' => $vinculo->producto->id, 'codigo' => $vinculo->producto->codigo, 'nombre' => $vinculo->producto->nombre]
                : null,
        ];
    }

    private function etiquetaProducto(Vinculo $vinculo): string
    {
        $p = $vinculo->producto;

        return $p ? '#'.$p->id.' · '.($p->codigo ?: 's/código').' — '.$p->nombre : '—';
    }

    private function dinero(float $importe): string
    {
        return '$ '.number_format($importe, 2, ',', '.');
    }
}
