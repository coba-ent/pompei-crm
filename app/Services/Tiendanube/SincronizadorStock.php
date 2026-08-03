<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Deposito;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Cache;

/**
 * Empuja hacia Tiendanube el stock de los vínculos marcados pendientes por
 * MovimientoStockObserver (spec 018, plan.md §"Enfoque técnico" punto 2), vía
 * el cliente REST (spec 024). Candado propio (FR-008), cortes previos al
 * bucle (FR-009/FR-010, research.md R7) y continuidad ante el rechazo de un
 * vínculo puntual (FR-014/FR-015). La REST API clásica no tiene equivalente
 * al batch `update_stock_and_price` del MCP (research.md R4 de spec 024): se
 * envía una `PUT` por vínculo pendiente, sin loteo.
 *
 * `sincronizarTodos()` (spec 035) reutiliza el mismo loop de `sincronizar()`
 * vía `procesarVinculos()`, pero recorriendo TODOS los vínculos en vez de
 * sólo los pendientes — sostén de la "Sincronización forzada".
 */
class SincronizadorStock
{
    public const LOCK_KEY = 'tn:sincronizar_stock';

    public function __construct(
        private readonly ClienteTiendanubeRest $cliente,
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
     * "Sincronización forzada" (spec 035, FR-002/FR-003): recorre TODOS los
     * vínculos de la integración, no sólo los pendientes. Mismos cortes y
     * candado que `ejecutar()` — comparten `verificarCortes()` y
     * `self::LOCK_KEY`, así que no pueden correr en paralelo entre sí ni con
     * el cron/"Sincronizar ahora".
     *
     * @return array{ok: bool, tipo?: string, mensaje: string, actualizados?: int, con_error?: int}
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
            $conexion = TiendanubeConexionRest::actual();
            $depositoTn = $conexion->depositoEfectivo();

            $resultado = $this->procesarVinculos(
                TiendanubeVarianteProducto::with('producto')->get(),
                $depositoTn
            );

            $conexion->update([
                'stock_ultima_sync_en' => now(),
                'stock_ultima_sync_resultado' => "OK: {$resultado['actualizados']} variantes actualizadas, {$resultado['con_error']} con error.",
            ]);

            return [
                'ok' => true,
                'mensaje' => "{$resultado['actualizados']} variantes actualizadas en Tiendanube.",
                'actualizados' => $resultado['actualizados'],
                'con_error' => $resultado['con_error'],
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Cortes previos al bucle de vínculos pendientes (FR-009/FR-010): función
     * desactivada, modo sólo lectura o conexión caída/no configurada. Un único
     * registro en el historial por corte, nunca uno por vínculo — mismo
     * criterio que SincronizadorOrdenes::verificarCortes() (research.md R7).
     */
    private function verificarCortes(): ?array
    {
        if (! (bool) FuncionAvanzada::where('clave', 'tiendanube')->value('activa')) {
            return $this->bloquear('La función "Tiendanube" está desactivada en Funciones Avanzadas.');
        }

        $conexion = TiendanubeConexionRest::actual();

        if ($conexion->modo_solo_lectura) {
            return $this->bloquear('Bloqueada por el modo sólo lectura: las escrituras hacia Tiendanube están deshabilitadas.');
        }

        if (! $conexion->estaCompleta() || $conexion->estado === EstadoConexion::Caida) {
            return $this->bloquear('No hay una conexión con Tiendanube establecida. Hace falta reconectar Tiendanube (soporte técnico).');
        }

        return null;
    }

    private function bloquear(string $mensaje): array
    {
        TiendanubeRestOperacionLog::registrar([
            'operacion' => 'sincronizar_stock',
            'metodo' => 'PUT',
            'endpoint' => '/',
            'sentido' => 'escritura',
            'resultado' => 'bloqueada',
            'usuario_id' => auth()->id(),
        ]);

        return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => $mensaje];
    }

    private function sincronizar(): array
    {
        $conexion = TiendanubeConexionRest::actual();
        $depositoTn = $conexion->depositoEfectivo();

        $resultado = $this->procesarVinculos(
            TiendanubeVarianteProducto::pendientes()->with('producto')->get(),
            $depositoTn
        );

        $conexion->update([
            'stock_ultima_sync_en' => now(),
            'stock_ultima_sync_resultado' => "OK: {$resultado['actualizados']} variantes actualizadas, {$resultado['con_error']} con error.",
        ]);

        return [
            'ok' => true,
            'mensaje' => "{$resultado['actualizados']} variantes actualizadas en Tiendanube.",
            'actualizados' => $resultado['actualizados'],
            'con_error' => $resultado['con_error'],
        ];
    }

    /**
     * Loop compartido entre `sincronizar()` (pendientes) y `sincronizarTodos()`
     * (spec 035): recibe la colección de vínculos ya cargados con `producto` y
     * calcula/envía el stock de cada uno, sin cortar el resto ante un error
     * puntual (FR-014/FR-015).
     *
     * @param  iterable<TiendanubeVarianteProducto>  $vinculos
     * @return array{actualizados: int, con_error: int}
     */
    private function procesarVinculos(iterable $vinculos, Deposito $depositoTn): array
    {
        $actualizados = 0;
        $conError = 0;

        foreach ($vinculos as $vinculo) {
            if (! $vinculo->producto) {
                // Producto eliminado: nada que calcular, se limpia el pendiente para no reintentar en vano.
                $vinculo->update(['stock_pendiente' => false]);

                continue;
            }

            if (blank($vinculo->tn_product_id)) {
                // FR-005a: vínculo incompleto, se señala sin llamar a la API.
                $conError++;
                $vinculo->update([
                    'stock_error' => 'Vínculo incompleto: falta el producto de Tiendanube',
                    'stock_error_en' => now(),
                ]);

                continue;
            }

            $cantidad = (int) max(0, $this->stock->disponibilidad($vinculo->producto, null, $depositoTn));

            if ($this->enviarUno($vinculo, $cantidad)) {
                $actualizados++;
            } else {
                $conError++;
            }
        }

        return ['actualizados' => $actualizados, 'con_error' => $conError];
    }

    /** `PUT /products/{product_id}/variants/{variant_id}` con `{"stock": N}` — sin batch (research.md R4). */
    private function enviarUno(TiendanubeVarianteProducto $vinculo, int $cantidad): bool
    {
        $respuesta = $this->cliente->escribir(
            'PUT',
            "products/{$vinculo->tn_product_id}/variants/{$vinculo->variant_id}",
            ['stock' => $cantidad]
        );

        if ($respuesta->fallo()) {
            $vinculo->update([
                'stock_error' => $respuesta->mensajeError ?? 'Tiendanube rechazó la actualización.',
                'stock_error_en' => now(),
            ]);

            return false;
        }

        $vinculo->update([
            'stock_pendiente' => false,
            'stock_sincronizado_en' => now(),
            'stock_error' => null,
            'stock_error_en' => null,
        ]);

        return true;
    }
}
