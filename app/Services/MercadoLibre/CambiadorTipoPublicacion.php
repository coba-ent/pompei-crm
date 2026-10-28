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
 *
 * Las cuotas (ajuste 16/10/2026, FR-019..FR-021) se eligen en la misma confirmación y se aplican
 * **después** del cambio de tipo, porque cada campaña sólo vale para su tipo. Si ML las rechaza, el
 * cambio de tipo y precio queda hecho: la publicación sigue con las cuotas que ML le asigna.
 */
class CambiadorTipoPublicacion
{
    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
        private readonly SincronizadorPrecios $sincronizadorPrecios,
        private readonly ProcesadorCambioTipoDetectado $procesadorDetectado,
    ) {}

    /**
     * Arma la confirmación (contracts §1). Consulta a ML el tipo vigente y, para subir a Premium, si está disponible;
     * lo único que escribe es el tipo del vínculo cuando el de ML difiere del guardado.
     *
     * `$destino` null sólo para vínculos sin clasificar: se propone el opuesto del tipo real.
     * Con `ok = false` hubo un error de comunicación con ML (no un bloqueo de negocio).
     *
     * `$conCuotas` arma las opciones de cuotas del destino (una consulta más a ML) y `$conCostos`
     * agrega el cargo de ML de cada opción (una consulta por opción): la ejecución sólo necesita
     * lo primero, y sólo cuando se eligieron cuotas.
     */
    public function previsualizar(Vinculo $vinculo, ?string $destino = null, bool $conCuotas = true, bool $conCostos = true): array
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
            'cuotas_actual' => null,
            'cuotas_actual_etiqueta' => null,
            'cuotas_opciones' => [],
            'cuotas_sugerida' => null,
            'aviso_pierde_cuotas' => null,
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

        // Un Clásica ↔ Premium hecho en ML NO se guarda acá: guardarlo sin su precio "consumía" el
        // cambio y la publicación quedaba con el precio del tipo anterior (spec 116, FR-011a). Lo
        // consolida ProcesadorCambioTipoDetectado, al confirmar o en la próxima revisión de tipos.
        if ($tipoVigente && $tipoVigente !== $vinculo->listing_type_id
            && ! ProcesadorCambioTipoDetectado::esCambioDetectado($vinculo->listing_type_id, $tipoVigente)) {
            $vinculo->update(['listing_type_id' => $tipoVigente, 'listing_type_sincronizado_en' => now()]);
        }

        $base['tipo_actual'] = $tipoVigente;
        $base['tipo_actual_etiqueta'] = Vinculo::etiquetaTipo($tipoVigente);
        $base['cuotas_actual'] = CampaniasCuotas::vigente((array) ($item->datos['tags'] ?? []));
        $base['cuotas_actual_etiqueta'] = CampaniasCuotas::etiqueta($base['cuotas_actual'], $tipoVigente);

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

        // spec 116 (FR-016): toda Premium tiene cuotas sin interés —sin campaña, las 6 que ML le pone
        // por defecto— y al pasar a Clásica las pierde.
        if ($destino === Vinculo::TIPO_CLASICA && $tipoVigente === Vinculo::TIPO_PREMIUM) {
            $base['aviso_pierde_cuotas'] = CampaniasCuotas::etiqueta($base['cuotas_actual'], Vinculo::TIPO_PREMIUM);
        }

        if ($tipoVigente === $destino) {
            return [...$base, 'sin_cambios' => true];
        }

        // Sólo la subida se valida antes de confirmar. `available_downgrades` devuelve vacío para todas
        // las Premium de la cuenta (verificado el 28/10/2026, modelo User Products) aunque la baja
        // desde la pantalla de ML funciona: consultarlo bloqueaba el 100% de los pasos a Clásica
        // (spec 116, research R1). Si ML no admite la baja, lo dice el POST de cambiarTipo().
        if ($destino === Vinculo::TIPO_PREMIUM) {
            $disponibles = $this->cliente->obtener('consultar_tipo_publicacion', "/items/{$vinculo->ml_item_id}/available_upgrades");

            if ($disponibles->fallo()) {
                return ['ok' => false, 'mensaje' => $disponibles->mensajeError ?? 'No se pudo consultar a Mercado Libre qué tipos admite la publicación.'];
            }

            if (! in_array($destino, $this->idsDisponibles($disponibles->datos), true)) {
                return [...$base, 'motivo_bloqueo' => 'Mercado Libre no ofrece el tipo '.Vinculo::etiquetaTipo($destino).' para esta publicación.'];
            }
        }

        if ($conCuotas) {
            $base['cuotas_opciones'] = $this->opcionesCuotas($item->datos, $destino, $base['precio_destino'], $conCostos);
            $base['cuotas_sugerida'] = in_array($base['cuotas_actual'], array_column($base['cuotas_opciones'], 'id'), true)
                ? $base['cuotas_actual']
                : CampaniasCuotas::SIN_CAMPANIA;
        }

        return [...$base, 'puede_confirmar' => true];
    }

    /**
     * Ejecuta el cambio (contracts §2). Re-evalúa todo con `previsualizar()`: no confía en lo que
     * vio la pantalla. Registra siempre una fila de historial (FR-012).
     *
     * `$cuotas` es la campaña elegida (`CampaniasCuotas`); null = no tocar las cuotas, que quedan
     * como ML las deje al cambiar el tipo.
     *
     * @return array{ok: bool, resultado: string, mensaje: string, candado?: bool}
     */
    public function ejecutar(Vinculo $vinculo, string $destino, ?int $usuarioId, ?string $loteId = null, ?string $cuotas = null): array
    {
        $lock = Cache::lock(SincronizadorPrecios::LOCK_KEY, 60);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            $mensaje = 'Hay una sincronización de precios en curso, probá en unos minutos.';
            $this->registrar($vinculo, $destino, ['cuotas_elegidas' => $cuotas], Cambio::RESULTADO_BLOQUEADO, $mensaje, $usuarioId, $loteId);

            return ['ok' => false, 'resultado' => Cambio::RESULTADO_BLOQUEADO, 'mensaje' => $mensaje, 'candado' => true];
        }

        try {
            return $this->ejecutarConCandado($vinculo, $destino, $usuarioId, $loteId, $cuotas);
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
            // spec 116 (FR-017): sin consultar a ML no se sabe qué cuotas tiene cada una; aviso general.
            'aviso_pierde_cuotas_lote' => $destino === Vinculo::TIPO_CLASICA,
            // Sin consultar a ML: la lista documentada. Si la categoría de alguna publicación no
            // admite la opción elegida, esa queda bloqueada al procesarla (FR-021).
            'cuotas_opciones' => CampaniasCuotas::opcionesPorDefecto($destino),
            'cuotas_sugerida' => CampaniasCuotas::SIN_CAMPANIA,
        ];
    }

    private function ejecutarConCandado(Vinculo $vinculo, string $destino, ?int $usuarioId, ?string $loteId, ?string $cuotas): array
    {
        $previa = $this->previsualizar($vinculo, $destino, conCuotas: $cuotas !== null, conCostos: false);
        $previa['cuotas_elegidas'] = $cuotas;
        $vinculo->refresh();

        if (! $previa['ok']) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_FALLIDO, $previa['mensaje'], $usuarioId, $loteId);
        }

        $etiquetaDestino = Vinculo::etiquetaTipo($destino);

        if ($previa['sin_cambios'] && ProcesadorCambioTipoDetectado::esCambioDetectado($vinculo->listing_type_id, $destino)) {
            // ML ya lo tenía en el destino: alguien lo cambió allá. Se procesa como cambio detectado,
            // que publica el precio de ese tipo y registra la única fila de historial (FR-011a).
            $procesado = $this->procesadorDetectado->procesar($vinculo, $destino, $usuarioId);

            return ['ok' => true, 'resultado' => Cambio::RESULTADO_SIN_CAMBIOS,
                'mensaje' => "La publicación {$vinculo->ml_item_id} ya era {$etiquetaDestino} en Mercado Libre. ".$procesado['mensaje']];
        }

        if ($previa['sin_cambios']) {
            $this->cerrarRetencionAbierta($vinculo);

            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_SIN_CAMBIOS,
                "La publicación {$vinculo->ml_item_id} ya era {$etiquetaDestino} en Mercado Libre.", $usuarioId, $loteId);
        }

        if (! $previa['puede_confirmar']) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_BLOQUEADO, $previa['motivo_bloqueo'], $usuarioId, $loteId);
        }

        if ($cuotas !== null && ! in_array($cuotas, array_column($previa['cuotas_opciones'], 'id'), true)) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_BLOQUEADO,
                'Mercado Libre no ofrece "'.CampaniasCuotas::etiqueta($cuotas, $destino).'" para esta publicación; no se cambió nada.',
                $usuarioId, $loteId);
        }

        $precioAnterior = $previa['precio_actual'];
        $precioNuevo = (float) $previa['precio_destino'];
        $hayQueEnviarPrecio = $precioAnterior === null || abs($precioAnterior - $precioNuevo) >= 0.005;

        return $destino === Vinculo::TIPO_PREMIUM
            ? $this->pasarAPremium($vinculo, $previa, $precioAnterior, $precioNuevo, $hayQueEnviarPrecio, $usuarioId, $loteId, $cuotas)
            : $this->pasarAClasica($vinculo, $previa, $precioNuevo, $hayQueEnviarPrecio, $usuarioId, $loteId, $cuotas);
    }

    /** Primero el precio, después el tipo y al final las cuotas: nunca Premium con precio de Clásica (FR-008a). */
    private function pasarAPremium(Vinculo $vinculo, array $previa, ?float $precioAnterior, float $precioNuevo, bool $hayQueEnviarPrecio, ?int $usuarioId, ?string $loteId, ?string $cuotas): array
    {
        $destino = Vinculo::TIPO_PREMIUM;

        if ($hayQueEnviarPrecio && ! $this->sincronizadorPrecios->enviarUno($vinculo, $precioNuevo, omitirCorte: true)) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_FALLIDO,
                'No se pudo publicar el precio Premium; el tipo no se cambió: '.($vinculo->fresh()->precio_error ?? 'Mercado Libre rechazó el precio.'),
                $usuarioId, $loteId);
        }

        $error = $this->cambiarTipo($vinculo, $destino, $itemTrasCambio);

        if ($error === null) {
            $this->cerrarRetencionAbierta($vinculo);
            $mensaje = "Publicación {$vinculo->ml_item_id} pasada a Premium a ".$this->dinero($precioNuevo);

            if ($errorCuotas = $this->aplicarCuotas($vinculo, $cuotas, $itemTrasCambio)) {
                return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_CAMBIADO_CUOTAS_PENDIENTES,
                    $mensaje.', pero Mercado Libre no aplicó las cuotas elegidas ('.$errorCuotas.'): quedó con las que asigna por defecto.',
                    $usuarioId, $loteId);
            }

            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_CAMBIADO,
                $mensaje.$this->sufijoCuotas($cuotas, $destino).'.', $usuarioId, $loteId);
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

    /**
     * Primero el tipo, después el precio: si el precio falla, cobra de más y no de menos (FR-008b).
     * Las cuotas se intentan aunque el precio haya fallado: son independientes.
     */
    private function pasarAClasica(Vinculo $vinculo, array $previa, float $precioNuevo, bool $hayQueEnviarPrecio, ?int $usuarioId, ?string $loteId, ?string $cuotas): array
    {
        $destino = Vinculo::TIPO_CLASICA;

        if ($error = $this->cambiarTipo($vinculo, $destino, $itemTrasCambio)) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_FALLIDO, $error, $usuarioId, $loteId);
        }

        $precioFallo = $hayQueEnviarPrecio && ! $this->sincronizadorPrecios->enviarUno($vinculo, $precioNuevo, omitirCorte: true);
        $errorCuotas = $this->aplicarCuotas($vinculo, $cuotas, $itemTrasCambio);
        $avisoCuotas = $errorCuotas ? ' Mercado Libre no aplicó las cuotas elegidas ('.$errorCuotas.'); revisalas en la publicación.' : '';

        if ($precioFallo) {
            $vinculo->update(['precio_pendiente' => true]);

            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_CAMBIADO_PRECIO_PENDIENTE,
                'Pasada a Clásica; el precio quedó pendiente: '.($vinculo->fresh()->precio_error ?? 'Mercado Libre rechazó el precio.').$avisoCuotas,
                $usuarioId, $loteId);
        }

        $this->cerrarRetencionAbierta($vinculo);
        $mensaje = "Publicación {$vinculo->ml_item_id} pasada a Clásica a ".$this->dinero($precioNuevo);

        if ($errorCuotas) {
            return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_CAMBIADO_CUOTAS_PENDIENTES, $mensaje.'.'.$avisoCuotas, $usuarioId, $loteId);
        }

        return $this->resultado($vinculo, $destino, $previa, Cambio::RESULTADO_CAMBIADO,
            $mensaje.$this->sufijoCuotas($cuotas, $destino).'.', $usuarioId, $loteId);
    }

    /**
     * Deja la publicación con la campaña de cuotas elegida (FR-020). ML reemplaza la lista entera de
     * tags, así que se parte de los tags vigentes **después** del cambio de tipo; si no se pueden
     * leer no se envía nada —mandar sólo la campaña borraría los demás tags—. Si la campaña ya es
     * la vigente no hay escritura (el caso común: Premium con las 6 cuotas por defecto).
     *
     * Devuelve el motivo del rechazo o null si quedó aplicada (o no había nada que aplicar).
     */
    private function aplicarCuotas(Vinculo $vinculo, ?string $cuotas, ?array $itemTrasCambio): ?string
    {
        if ($cuotas === null) {
            return null;
        }

        $tags = $itemTrasCambio['tags'] ?? null;

        if (! is_array($tags)) {
            $item = $this->cliente->obtener('consultar_tipo_publicacion', "/items/{$vinculo->ml_item_id}");
            $tags = $item->fallo() ? null : ($item->datos['tags'] ?? null);
        }

        if (! is_array($tags)) {
            return 'no se pudieron leer los tags de la publicación';
        }

        if (CampaniasCuotas::vigente($tags) === $cuotas) {
            return null;
        }

        $respuesta = $this->cliente->enviar('cambiar_cuotas_publicacion', 'PUT', "/items/{$vinculo->ml_item_id}", [
            'tags' => CampaniasCuotas::tagsCon($tags, $cuotas),
        ]);

        return $respuesta->fallo() ? ($respuesta->mensajeError ?? 'Mercado Libre rechazó las cuotas') : null;
    }

    /**
     * Campañas que admite la categoría para el tipo destino, con el cargo de ML de cada una cuando
     * se piden costos. Si ML no responde se ofrece la lista documentada: elegir una que la
     * categoría no admita termina en "cuotas pendientes", nunca en una publicación rota.
     */
    private function opcionesCuotas(array $item, string $destino, ?float $precio, bool $conCostos): array
    {
        $opciones = null;

        if ($categoria = $item['category_id'] ?? null) {
            $campanias = $this->cliente->obtener('consultar_campanias_cuotas', '/special_installments/campaigns', [
                'category_id' => $categoria,
                'listing_type_id' => $destino,
            ]);

            if (! $campanias->fallo()) {
                $opciones = CampaniasCuotas::opcionesDesdeMl($campanias->datos, $destino);
            }
        }

        $opciones ??= CampaniasCuotas::opcionesPorDefecto($destino);

        if (! $conCostos || ! $precio) {
            return $opciones;
        }

        return array_map(fn ($opcion) => [
            ...$opcion,
            'cargo' => $this->cargoVenta($item, $destino, $opcion['id'], $precio),
        ], $opciones);
    }

    /** Cargo por vender de ML (comisión + costo de cuotas) para un precio; null si no responde. */
    private function cargoVenta(array $item, string $destino, string $campania, float $precio): ?array
    {
        $parametros = array_filter([
            'price' => $precio,
            'listing_type_id' => $destino,
            'tags' => $campania === CampaniasCuotas::SIN_CAMPANIA ? null : $campania,
            'domain_id' => $item['domain_id'] ?? null,
        ], fn ($v) => $v !== null);

        $sitio = $item['site_id'] ?? 'MLA';
        $respuesta = $this->cliente->obtener('consultar_cargos_venta', "/sites/{$sitio}/listing_prices", $parametros);

        if ($respuesta->fallo()) {
            return null;
        }

        $filas = array_is_list($respuesta->datos) ? $respuesta->datos : [$respuesta->datos];
        $fila = collect($filas)->first(fn ($f) => is_array($f) && ($f['listing_type_id'] ?? $destino) === $destino);

        if (! is_array($fila) || ! isset($fila['sale_fee_details']['percentage_fee'])) {
            return null;
        }

        return [
            'porcentaje' => (float) $fila['sale_fee_details']['percentage_fee'],
            'importe' => isset($fila['sale_fee_amount']) ? (float) $fila['sale_fee_amount'] : null,
        ];
    }

    private function sufijoCuotas(?string $cuotas, string $destino): string
    {
        return $cuotas === null ? '' : ', '.mb_strtolower(CampaniasCuotas::etiqueta($cuotas, $destino));
    }

    /**
     * POST del cambio de tipo (research R1). Devuelve el motivo del rechazo o null si ML lo aceptó;
     * en `$item` deja el ítem que devolvió ML (de ahí salen los tags para aplicar las cuotas).
     */
    private function cambiarTipo(Vinculo $vinculo, string $destino, ?array &$item = null): ?string
    {
        $item = null;
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
        $item = $respuesta->datos;

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

    /** `available_upgrades` devuelve una lista de tipos (`[{id, name, ...}]`). */
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
            'ok' => in_array($resultado, [Cambio::RESULTADO_CAMBIADO, Cambio::RESULTADO_CAMBIADO_PRECIO_PENDIENTE, Cambio::RESULTADO_CAMBIADO_CUOTAS_PENDIENTES, Cambio::RESULTADO_SIN_CAMBIOS], true),
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
            'cuotas' => $previa['cuotas_elegidas'] ?? null,
            'resultado' => $resultado,
            'origen' => Cambio::ORIGEN_CRM,
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
