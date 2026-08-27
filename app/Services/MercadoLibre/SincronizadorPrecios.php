<?php

namespace App\Services\MercadoLibre;

use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use Illuminate\Support\Facades\Cache;

/**
 * Empuja hacia Mercado Libre los precios de los vínculos cuyo producto cambió
 * de precio dentro de la Lista de Precios configurada (spec 016, plan.md §4).
 * Contraparte de precio de SincronizadorStock (spec 013): mismos cortes de
 * kill-switch (FR-011/FR-012) y misma continuidad ante el rechazo de un
 * vínculo puntual (FR-010), pero sin corrida programada — el disparo es por
 * evento (PrecioProductoObserver) o manual (ejecutar()/sincronizarListaCompleta()).
 */
class SincronizadorPrecios
{
    public const LOCK_KEY = 'ml:sincronizar_precios';

    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
    ) {
    }

    /**
     * Envía el precio vigente de un vínculo puntual. Usado directamente por
     * PrecioProductoObserver (un único vínculo) y, en bucle, por ejecutar() y
     * sincronizarListaCompleta() (que ya verificaron los cortes una sola vez
     * antes de iterar — ver verificarCortes()).
     */
    public function enviarUno(MercadoLibrePublicacionProducto $vinculo, float $precio): bool
    {
        // Se marca pendiente ANTES de evaluar cortes o de intentar el envío: así
        // un intento bloqueado (función desactivada, sólo lectura, conexión
        // caída) deja igual "conservado el pendiente para el próximo intento
        // válido" (FR-011/FR-012), en vez de perder el cambio porque nada lo
        // marcó pendiente todavía (research.md R4).
        $vinculo->update(['precio_pendiente' => true]);

        if ($bloqueo = $this->verificarCortes()) {
            $this->bloquear($bloqueo);

            return false;
        }

        $respuesta = $this->cliente->enviar(
            'sincronizar_precio',
            'PUT',
            "/items/{$vinculo->ml_item_id}",
            ['price' => $precio]
        );

        if ($respuesta->fallo()) {
            $vinculo->update([
                'precio_error' => $respuesta->mensajeError ?? 'Mercado Libre rechazó la actualización.',
                'precio_error_en' => now(),
            ]);

            return false;
        }

        $vinculo->update([
            'precio_pendiente' => false,
            'precio_sincronizado_en' => now(),
            'precio_error' => null,
            'precio_error_en' => null,
        ]);

        return true;
    }

    /**
     * "Sincronizar precios ahora" (US3): reintenta todos los vínculos con
     * precio pendiente o con error. Candado propio (FR-015, independiente del
     * de stock/órdenes) y corte único antes del bucle (research.md R5 — evita
     * un registro de bloqueo por cada vínculo pendiente).
     *
     * @return array{ok: bool, tipo?: string, mensaje: string, actualizados?: int, con_error?: int}
     */
    public function ejecutar(): array
    {
        $configuracion = MercadoLibreConfiguracion::actual();

        if (! $configuracion->lista_precio_id) {
            return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => 'No hay ninguna Lista de Precios configurada para Mercado Libre.'];
        }

        if ($bloqueo = $this->verificarCortes()) {
            return $this->bloquear($bloqueo);
        }

        $lock = Cache::lock(self::LOCK_KEY, 300);

        if (! $lock->get()) {
            return ['ok' => false, 'tipo' => 'salteada', 'mensaje' => 'Ya hay una sincronización de precios en curso.'];
        }

        try {
            return $this->enviarPendientes($configuracion);
        } finally {
            $lock->release();
        }
    }

    /**
     * Al cambiar cuál es la Lista de Precios configurada (US5, FR-007):
     * empuja de inmediato el precio vigente de la nueva lista a todos los
     * vínculos que tengan precio cargado ahí.
     *
     * @return array{ok: bool, tipo?: string, mensaje: string, actualizados?: int, con_error?: int}
     */
    public function sincronizarListaCompleta(int $listaPrecioId): array
    {
        // A diferencia de ejecutar(), acá NO se corta antes del bucle: aunque
        // esté bloqueado, cada vínculo con precio en la lista nueva tiene que
        // quedar igual marcado precio_pendiente = true para el próximo intento
        // válido (US5 escenario 3, contracts §3) — el bloqueo sólo evita el
        // intento de envío real (ver enviarUno()), no el marcado.
        $bloqueo = $this->verificarCortes();
        $configuracion = MercadoLibreConfiguracion::actual();
        $actualizados = 0;
        $conError = 0;

        foreach (MercadoLibrePublicacionProducto::with('producto')->get() as $vinculo) {
            if (! $vinculo->producto) {
                continue;
            }

            // T014 (spec 050): sin importar cuál de las dos listas cambió
            // (general o Premium), sólo se empuja a los vínculos cuya lista
            // resuelta (por tipo de publicación) sea exactamente la que cambió
            // — misma regla que enviarPendientes(), sin lógica especial por caso.
            if ($this->resolverListaPrecio($vinculo, $configuracion) !== $listaPrecioId) {
                continue;
            }

            $precio = $vinculo->producto->precios()->where('lista_precio_id', $listaPrecioId)->value('precio');

            if ($precio === null) {
                continue;
            }

            if ($bloqueo) {
                $vinculo->update(['precio_pendiente' => true]);

                continue;
            }

            if ($this->enviarUno($vinculo, (float) $precio)) {
                $actualizados++;
            } else {
                $conError++;
            }
        }

        if ($bloqueo) {
            return $this->bloquear($bloqueo);
        }

        return [
            'ok' => true,
            'mensaje' => "{$actualizados} productos actualizados en Mercado Libre.",
            'actualizados' => $actualizados,
            'con_error' => $conError,
        ];
    }

    private function enviarPendientes(MercadoLibreConfiguracion $configuracion): array
    {
        $actualizados = 0;
        $conError = 0;

        foreach (MercadoLibrePublicacionProducto::pendientesPrecio()->with('producto')->get() as $vinculo) {
            if (! $vinculo->producto) {
                $vinculo->update(['precio_pendiente' => false]);

                continue;
            }

            $listaPrecioId = $this->resolverListaPrecio($vinculo, $configuracion);

            if ($listaPrecioId === null) {
                continue;
            }

            $precio = $vinculo->producto->precios()->where('lista_precio_id', $listaPrecioId)->value('precio');

            if ($precio === null) {
                continue;
            }

            if ($this->enviarUno($vinculo, (float) $precio)) {
                $actualizados++;
            } else {
                $conError++;
            }
        }

        return [
            'ok' => true,
            'mensaje' => "{$actualizados} productos actualizados en Mercado Libre.",
            'actualizados' => $actualizados,
            'con_error' => $conError,
        ];
    }

    /**
     * Resuelve qué Lista de Precios corresponde a un vínculo (spec 050,
     * FR-006/007/008/009, research.md R5): si es Premium y su producto tiene
     * precio cargado en la lista Premium configurada, esa; si no —sea porque
     * no es Premium, no hay lista Premium configurada, o no tiene precio
     * ahí— la lista general. Evaluado por publicación individual (FR-011).
     *
     * Pública desde el 26/08/2026: `PrecioProductoObserver` la necesita para no empujar el precio
     * de la lista general a una publicación Premium. Antes resolvía por su cuenta —miraba sólo la
     * lista general— y una edición masiva de precios le bajó el precio a 18 publicaciones Premium.
     */
    public function resolverListaPrecio(MercadoLibrePublicacionProducto $vinculo, MercadoLibreConfiguracion $configuracion): ?int
    {
        if ($vinculo->esPremium() && $configuracion->lista_precio_id_premium && $vinculo->producto) {
            $tienePrecioPremium = $vinculo->producto->precios()
                ->where('lista_precio_id', $configuracion->lista_precio_id_premium)
                ->exists();

            if ($tienePrecioPremium) {
                return $configuracion->lista_precio_id_premium;
            }
        }

        return $configuracion->lista_precio_id;
    }

    /**
     * Verificación pura (FR-011/FR-012), sin efecto de log: los mismos tres
     * cortes que SincronizadorStock::verificarCortes(). No registra nada acá
     * —eso lo decide cada llamador— para que un bloqueo detectado una vez
     * antes de recorrer varios vínculos (ejecutar()/sincronizarListaCompleta())
     * no termine registrando un "bloqueada" por cada vínculo cuando enviarUno()
     * vuelve a evaluar la misma condición dentro del bucle.
     */
    private function verificarCortes(): ?string
    {
        if (! (bool) FuncionAvanzada::where('clave', 'mercadolibre')->value('activa')) {
            return 'La función "Mercado Libre" está desactivada en Funciones Avanzadas.';
        }

        if (MercadoLibreConfiguracion::actual()->modo_solo_lectura) {
            return 'Bloqueada por el modo sólo lectura: las escrituras hacia Mercado Libre están deshabilitadas.';
        }

        if (! MercadoLibreCuenta::conectada()->exists()) {
            return 'No hay ninguna cuenta de Mercado Libre conectada. Volvé a conectar la cuenta.';
        }

        return null;
    }

    private function bloquear(string $mensaje): array
    {
        MercadoLibreOperacionLog::registrar([
            'operacion' => 'sincronizar_precio',
            'metodo' => 'PUT',
            'endpoint' => '/items/{id}',
            'sentido' => 'escritura',
            'resultado' => 'bloqueada',
            'usuario_id' => auth()->id(),
        ]);

        return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => $mensaje];
    }
}
