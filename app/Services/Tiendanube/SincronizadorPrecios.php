<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use Illuminate\Support\Facades\Cache;

/**
 * Empuja hacia Tiendanube los precios de los vínculos cuyo producto cambió de
 * precio dentro de la Lista de Precios configurada (spec 018 ampliación,
 * plan.md §8). Contraparte de precio de SincronizadorStock: mismos cortes de
 * kill-switch (FR-032/FR-033) y misma continuidad ante el rechazo de un
 * vínculo puntual (FR-031), pero sin corrida programada — el disparo es por
 * evento (PrecioProductoObserver) o manual (ejecutar()/sincronizarListaCompleta()).
 * Reutiliza la misma tool `update_stock_and_price` que el flujo de stock, un
 * ítem por llamada (research.md R9) — no se lotea, los cambios de precio son
 * esporádicos y unitarios por evento.
 */
class SincronizadorPrecios
{
    public const LOCK_KEY = 'tn:sincronizar_precios';

    public function __construct(
        private readonly ClienteTiendanube $cliente,
    ) {
    }

    /**
     * Envía el precio vigente de un vínculo puntual. Usado directamente por
     * PrecioProductoObserver (un único vínculo) y, en bucle, por ejecutar() y
     * sincronizarListaCompleta() (que ya verificaron los cortes una sola vez
     * antes de iterar — ver verificarCortes()).
     */
    public function enviarUno(TiendanubeVarianteProducto $vinculo, float $precio): bool
    {
        // Se marca pendiente ANTES de evaluar cortes o de intentar el envío: así
        // un intento bloqueado (función desactivada, sólo lectura, conexión
        // caída) deja igual "conservado el pendiente para el próximo intento
        // válido" (FR-032/FR-033), en vez de perder el cambio porque nada lo
        // marcó pendiente todavía (research.md R4, mismo criterio que ML).
        $vinculo->update(['precio_pendiente' => true]);

        if ($bloqueo = $this->verificarCortes()) {
            $this->bloquear($bloqueo);

            return false;
        }

        if (blank($vinculo->tn_product_id)) {
            $vinculo->update([
                'precio_error' => 'Vínculo incompleto: falta el producto de Tiendanube',
                'precio_error_en' => now(),
            ]);

            return false;
        }

        $respuesta = $this->cliente->escribir('update_stock_and_price', ['updates' => [[
            'product_id' => $vinculo->tn_product_id,
            'variant_id' => $vinculo->variant_id,
            'price' => $precio,
        ]]]);

        if ($respuesta->fallo()) {
            $vinculo->update([
                'precio_error' => $respuesta->mensajeError ?? 'Tiendanube rechazó la actualización.',
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
     * "Sincronizar precios ahora" (US7): reintenta todos los vínculos con
     * precio pendiente o con error. Candado propio (FR-036, independiente del
     * de stock/órdenes) y corte único antes del bucle (research.md R7 — evita
     * un registro de bloqueo por cada vínculo pendiente).
     *
     * @return array{ok: bool, tipo?: string, mensaje: string, actualizados?: int, con_error?: int}
     */
    public function ejecutar(): array
    {
        $configuracion = TiendanubeConfiguracion::actual();

        if (! $configuracion->lista_precio_id) {
            return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => 'No hay ninguna Lista de Precios configurada para Tiendanube.'];
        }

        if ($bloqueo = $this->verificarCortes()) {
            return $this->bloquear($bloqueo);
        }

        $lock = Cache::lock(self::LOCK_KEY, 300);

        if (! $lock->get()) {
            return ['ok' => false, 'tipo' => 'salteada', 'mensaje' => 'Ya hay una sincronización de precios en curso.'];
        }

        try {
            return $this->enviarPendientes($configuracion->lista_precio_id);
        } finally {
            $lock->release();
        }
    }

    /**
     * Al cambiar cuál es la Lista de Precios configurada (US9, FR-028):
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
        // válido (US9 escenario 3, contracts §2a) — el bloqueo sólo evita el
        // intento de envío real (ver enviarUno()), no el marcado.
        $bloqueo = $this->verificarCortes();
        $actualizados = 0;
        $conError = 0;

        foreach (TiendanubeVarianteProducto::with('producto')->get() as $vinculo) {
            if (! $vinculo->producto) {
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
            'mensaje' => "{$actualizados} variantes actualizadas en Tiendanube.",
            'actualizados' => $actualizados,
            'con_error' => $conError,
        ];
    }

    private function enviarPendientes(int $listaPrecioId): array
    {
        $actualizados = 0;
        $conError = 0;

        foreach (TiendanubeVarianteProducto::pendientesPrecio()->with('producto')->get() as $vinculo) {
            if (! $vinculo->producto) {
                $vinculo->update(['precio_pendiente' => false]);

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
            'mensaje' => "{$actualizados} variantes actualizadas en Tiendanube.",
            'actualizados' => $actualizados,
            'con_error' => $conError,
        ];
    }

    /**
     * Verificación pura (FR-032/FR-033), sin efecto de log: los mismos tres
     * cortes que SincronizadorStock::verificarCortes(). No registra nada acá
     * —eso lo decide cada llamador— para que un bloqueo detectado una vez
     * antes de recorrer varios vínculos (ejecutar()/sincronizarListaCompleta())
     * no termine registrando un "bloqueada" por cada vínculo cuando enviarUno()
     * vuelve a evaluar la misma condición dentro del bucle.
     */
    private function verificarCortes(): ?string
    {
        if (! (bool) FuncionAvanzada::where('clave', 'tiendanube')->value('activa')) {
            return 'La función "Tiendanube" está desactivada en Funciones Avanzadas.';
        }

        $configuracion = TiendanubeConfiguracion::actual();

        if ($configuracion->modo_solo_lectura) {
            return 'Bloqueada por el modo sólo lectura: las escrituras hacia Tiendanube están deshabilitadas.';
        }

        if (! $configuracion->estaCompleta() || $configuracion->estado === EstadoConexion::Caida) {
            return 'No hay una conexión con Tiendanube establecida. Hace falta reconectar Tiendanube (soporte técnico).';
        }

        return null;
    }

    private function bloquear(string $mensaje): array
    {
        TiendanubeOperacionLog::registrar([
            'operacion' => 'sincronizar_precio',
            'metodo' => 'POST',
            'endpoint' => '/',
            'sentido' => 'escritura',
            'resultado' => 'bloqueada',
            'usuario_id' => auth()->id(),
        ]);

        return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => $mensaje];
    }
}
