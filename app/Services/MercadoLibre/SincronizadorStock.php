<?php

namespace App\Services\MercadoLibre;

use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Cache;

/**
 * Empuja hacia Mercado Libre el stock de los vínculos marcados pendientes por
 * MovimientoStockObserver (spec 013, plan.md §"Enfoque técnico" punto 2).
 * Candado propio (FR-008), cortes previos al bucle (FR-009/FR-010,
 * research.md R7) y continuidad ante el rechazo de un vínculo puntual
 * (FR-014/FR-015) — no se corta el resto de la corrida por un vínculo.
 */
class SincronizadorStock
{
    public const LOCK_KEY = 'ml:sincronizar_stock';

    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
        private readonly StockService $stock,
    ) {
    }

    /**
     * @return array{ok: bool, tipo?: string, mensaje: string, actualizados?: int, con_error?: int}
     */
    public function ejecutar(): array
    {
        if ($bloqueo = $this->verificarCortes()) {
            return $bloqueo;
        }

        $lock = Cache::lock(self::LOCK_KEY, 300);

        if (! $lock->get()) {
            return ['ok' => false, 'tipo' => 'salteada', 'mensaje' => 'Ya hay una sincronización de stock en curso.'];
        }

        try {
            return $this->sincronizar();
        } finally {
            $lock->release();
        }
    }

    /**
     * Cortes previos al bucle de vínculos pendientes (FR-009/FR-010): función
     * desactivada, modo sólo lectura o conexión caída/no configurada. Un único
     * registro en el historial por corte, nunca uno por vínculo — mismo criterio
     * que SincronizadorOrdenes::verificarCortes() (research.md R7).
     */
    private function verificarCortes(): ?array
    {
        if (! (bool) FuncionAvanzada::where('clave', 'mercadolibre')->value('activa')) {
            return $this->bloquear('La función "Mercado Libre" está desactivada en Funciones Avanzadas.');
        }

        if (MercadoLibreConfiguracion::actual()->modo_solo_lectura) {
            return $this->bloquear('Bloqueada por el modo sólo lectura: las escrituras hacia Mercado Libre están deshabilitadas.');
        }

        if (! MercadoLibreCuenta::conectada()->exists()) {
            return $this->bloquear('No hay ninguna cuenta de Mercado Libre conectada. Volvé a conectar la cuenta.');
        }

        return null;
    }

    private function bloquear(string $mensaje): array
    {
        MercadoLibreOperacionLog::registrar([
            'operacion' => 'sincronizar_stock',
            'metodo' => 'PUT',
            'endpoint' => '/items/{id}',
            'sentido' => 'escritura',
            'resultado' => 'bloqueada',
            'usuario_id' => auth()->id(),
        ]);

        return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => $mensaje];
    }

    private function sincronizar(): array
    {
        $configuracion = MercadoLibreConfiguracion::actual();
        $depositoMl = $configuracion->depositoEfectivo();

        $actualizados = 0;
        $conError = 0;

        foreach (MercadoLibrePublicacionProducto::pendientes()->with('producto')->get() as $vinculo) {
            if (! $vinculo->producto) {
                // Producto eliminado: nada que calcular, se limpia el pendiente para no reintentar en vano.
                $vinculo->update(['stock_pendiente' => false]);

                continue;
            }

            $cantidad = (int) max(0, $this->stock->disponibilidad($vinculo->producto, null, $depositoMl));

            $respuesta = $this->cliente->enviar(
                'sincronizar_stock',
                'PUT',
                "/items/{$vinculo->ml_item_id}",
                ['available_quantity' => $cantidad]
            );

            if ($respuesta->fallo()) {
                $conError++;
                $vinculo->update([
                    'stock_error' => $respuesta->mensajeError ?? 'Mercado Libre rechazó la actualización.',
                    'stock_error_en' => now(),
                ]);

                continue;
            }

            $actualizados++;
            $vinculo->update([
                'stock_pendiente' => false,
                'stock_sincronizado_en' => now(),
                'stock_error' => null,
                'stock_error_en' => null,
            ]);
        }

        $configuracion->update([
            'stock_ultima_sync_en' => now(),
            'stock_ultima_sync_resultado' => "OK: {$actualizados} productos actualizados, {$conError} con error.",
        ]);

        return [
            'ok' => true,
            'mensaje' => "{$actualizados} productos actualizados en Mercado Libre.",
            'actualizados' => $actualizados,
            'con_error' => $conError,
        ];
    }
}
