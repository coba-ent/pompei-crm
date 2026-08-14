<?php

namespace App\Services\MercadoLibre;

use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Deposito;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Cache;

/**
 * Empuja hacia Mercado Libre el stock de los vínculos marcados pendientes por
 * MovimientoStockObserver (spec 013, plan.md §"Enfoque técnico" punto 2).
 * Candado propio (FR-008), cortes previos al bucle (FR-009/FR-010,
 * research.md R7) y continuidad ante el rechazo de un vínculo puntual
 * (FR-014/FR-015) — no se corta el resto de la corrida por un vínculo.
 *
 * `sincronizarTodos()` (spec 035) reutiliza el mismo loop de `sincronizar()`
 * vía `procesarVinculos()`, pero recorriendo TODOS los vínculos en vez de
 * sólo los pendientes — sostén de la "Sincronización forzada".
 */
class SincronizadorStock
{
    public const LOCK_KEY = 'ml:sincronizar_stock';

    /** spec 063/FR-015: intentos consecutivos con el mismo error antes de marcar "requiere intervención". */
    public const MAX_INTENTOS_CONSECUTIVOS = 5;

    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
        private readonly StockService $stock,
    ) {
    }

    /**
     * @return array{ok: bool, tipo?: string, mensaje: string, actualizados?: int, con_error?: int, omitidos?: int}
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
     * "Sincronización forzada" (spec 035, FR-002/FR-003): recorre TODOS los
     * vínculos de la integración, no sólo los pendientes. Mismos cortes y
     * candado que `ejecutar()` — comparten `verificarCortes()` y
     * `self::LOCK_KEY`, así que no pueden correr en paralelo entre sí ni con
     * el cron/"Sincronizar ahora".
     *
     * @return array{ok: bool, tipo?: string, mensaje: string, actualizados?: int, con_error?: int, omitidos?: int}
     */
    public function sincronizarTodos(): array
    {
        if ($bloqueo = $this->verificarCortes()) {
            return $bloqueo;
        }

        $lock = Cache::lock(self::LOCK_KEY, 300);

        if (! $lock->get()) {
            return ['ok' => false, 'tipo' => 'salteada', 'mensaje' => 'Ya hay una sincronización de stock en curso.'];
        }

        try {
            $configuracion = MercadoLibreConfiguracion::actual();
            $depositoMl = $configuracion->depositoEfectivo();

            $resultado = $this->procesarVinculos(
                MercadoLibrePublicacionProducto::with('producto')->get(),
                $depositoMl
            );

            $mensajes = $this->mensajes($resultado);

            $configuracion->update([
                'stock_ultima_sync_en' => now(),
                'stock_ultima_sync_resultado' => $mensajes['historial'],
            ]);

            return [
                'ok' => true,
                'mensaje' => $mensajes['mensaje'],
                'actualizados' => $resultado['actualizados'],
                'con_error' => $resultado['con_error'],
                'omitidos' => $resultado['omitidos'],
            ];
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

        $resultado = $this->procesarVinculos(
            MercadoLibrePublicacionProducto::pendientes()->with('producto')->get(),
            $depositoMl
        );

        $mensajes = $this->mensajes($resultado);

        $configuracion->update([
            'stock_ultima_sync_en' => now(),
            'stock_ultima_sync_resultado' => $mensajes['historial'],
        ]);

        return [
            'ok' => true,
            'mensaje' => $mensajes['mensaje'],
            'actualizados' => $resultado['actualizados'],
            'con_error' => $resultado['con_error'],
            'omitidos' => $resultado['omitidos'],
        ];
    }

    /**
     * Loop compartido entre `sincronizar()` (pendientes) y `sincronizarTodos()`
     * (spec 035): recibe la colección de vínculos ya cargados con `producto` y
     * calcula/envía el stock de cada uno, sin cortar el resto ante un error
     * puntual (FR-014/FR-015).
     *
     * @param  iterable<MercadoLibrePublicacionProducto>  $vinculos
     * @return array{actualizados: int, con_error: int, omitidos: int}
     */
    private function procesarVinculos(iterable $vinculos, Deposito $depositoMl): array
    {
        $actualizados = 0;
        $conError = 0;

        $omitidos = 0;

        foreach ($vinculos as $vinculo) {
            // spec 065/FR-006: en una publicación Full, `available_quantity` refleja la
            // existencia del centro de distribución de Mercado Libre, que NO es escribible
            // por API. No es prudencia: del otro lado no hay destino de escritura. Se limpia
            // el pendiente (FR-007) para que no quede reintentándose eternamente ni contando
            // como error, y el reflejo inverso lo hace SincronizadorStockFull.
            if ($vinculo->esFull()) {
                $omitidos++;
                $vinculo->update(['stock_pendiente' => false]);

                continue;
            }

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

                $mensajeError = $respuesta->mensajeError ?? 'Mercado Libre rechazó la actualización.';
                // spec 063/FR-014: mismo error consecutivo → incrementa; error distinto → reinicia
                // la racha en 1 y refija "desde cuándo" (no tiene sentido contar rachas mezcladas).
                $mismoError = $vinculo->stock_error === $mensajeError;
                $intentos = $mismoError ? $vinculo->stock_intentos_fallidos + 1 : 1;

                $vinculo->update([
                    'stock_error' => $mensajeError,
                    'stock_error_en' => now(),
                    'stock_intentos_fallidos' => $intentos,
                    'stock_error_desde' => $mismoError ? $vinculo->stock_error_desde : now(),
                    // FR-015: a los 5 intentos consecutivos con el mismo error, deja de reintentarse.
                    'stock_requiere_intervencion' => $intentos >= self::MAX_INTENTOS_CONSECUTIVOS,
                ]);

                continue;
            }

            $actualizados++;
            $vinculo->update([
                'stock_pendiente' => false,
                'stock_sincronizado_en' => now(),
                'stock_error' => null,
                'stock_error_en' => null,
                'stock_intentos_fallidos' => 0,
                'stock_error_desde' => null,
                'stock_requiere_intervencion' => false,
                'ultimo_stock_publicado' => $cantidad,
            ]);
        }

        return ['actualizados' => $actualizados, 'con_error' => $conError, 'omitidos' => $omitidos];
    }

    /**
     * spec 065/FR-008 y SC-007: los sufijos de Full sólo aparecen si hubo algo que omitir.
     * Sin publicaciones Full vinculadas, los mensajes quedan **idénticos** a los de antes
     * de esta feature — es el criterio de no-regresión más importante.
     *
     * @param  array{actualizados: int, con_error: int, omitidos: int}  $resultado
     * @return array{mensaje: string, historial: string}
     */
    private function mensajes(array $resultado): array
    {
        $sufijo = $resultado['omitidos'] > 0
            ? ", {$resultado['omitidos']} omitidos por estar en Full"
            : '';

        return [
            'mensaje' => "{$resultado['actualizados']} productos actualizados en Mercado Libre{$sufijo}.",
            'historial' => "OK: {$resultado['actualizados']} productos actualizados, {$resultado['con_error']} con error{$sufijo}.",
        ];
    }
}
