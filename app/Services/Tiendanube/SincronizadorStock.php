<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Cache;

/**
 * Empuja hacia Tiendanube el stock de los vínculos marcados pendientes por
 * MovimientoStockObserver (spec 018, plan.md §"Enfoque técnico" punto 2).
 * Candado propio (FR-008), cortes previos al bucle (FR-009/FR-010,
 * research.md R7) y continuidad ante el rechazo de un vínculo puntual
 * (FR-014/FR-015). A diferencia de la contraparte de Mercado Libre, agrupa
 * los envíos en lotes de hasta 50 (`update_stock_and_price`, research.md R6)
 * en vez de una llamada por vínculo.
 */
class SincronizadorStock
{
    public const LOCK_KEY = 'tn:sincronizar_stock';

    private const TAMANO_LOTE = 50;

    public function __construct(
        private readonly ClienteTiendanube $cliente,
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
     * registro en el historial por corte, nunca uno por chunk — mismo criterio
     * que SincronizadorOrdenes::verificarCortes() (research.md R7).
     */
    private function verificarCortes(): ?array
    {
        if (! (bool) FuncionAvanzada::where('clave', 'tiendanube')->value('activa')) {
            return $this->bloquear('La función "Tiendanube" está desactivada en Funciones Avanzadas.');
        }

        $configuracion = TiendanubeConfiguracion::actual();

        if ($configuracion->modo_solo_lectura) {
            return $this->bloquear('Bloqueada por el modo sólo lectura: las escrituras hacia Tiendanube están deshabilitadas.');
        }

        if (! $configuracion->estaCompleta() || $configuracion->estado === EstadoConexion::Caida) {
            return $this->bloquear('No hay una conexión con Tiendanube establecida. Hace falta reconectar Tiendanube (soporte técnico).');
        }

        return null;
    }

    private function bloquear(string $mensaje): array
    {
        TiendanubeOperacionLog::registrar([
            'operacion' => 'sincronizar_stock',
            'metodo' => 'POST',
            'endpoint' => '/',
            'sentido' => 'escritura',
            'resultado' => 'bloqueada',
            'usuario_id' => auth()->id(),
        ]);

        return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => $mensaje];
    }

    private function sincronizar(): array
    {
        $configuracion = TiendanubeConfiguracion::actual();
        $depositoTn = $configuracion->depositoEfectivo();

        $actualizados = 0;
        $conError = 0;

        $vinculos = TiendanubeVarianteProducto::pendientes()->with('producto')->get();
        $porEnviar = [];

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

            $porEnviar[] = ['vinculo' => $vinculo, 'cantidad' => $cantidad];
        }

        foreach (array_chunk($porEnviar, self::TAMANO_LOTE) as $lote) {
            [$okLote, $errorLote] = $this->enviarLote($lote);
            $actualizados += $okLote;
            $conError += $errorLote;
        }

        $configuracion->update([
            'stock_ultima_sync_en' => now(),
            'stock_ultima_sync_resultado' => "OK: {$actualizados} variantes actualizadas, {$conError} con error.",
        ]);

        return [
            'ok' => true,
            'mensaje' => "{$actualizados} variantes actualizadas en Tiendanube.",
            'actualizados' => $actualizados,
            'con_error' => $conError,
        ];
    }

    /**
     * @param  array<int, array{vinculo: TiendanubeVarianteProducto, cantidad: int}>  $lote
     * @return array{0: int, 1: int} [actualizados, con_error]
     */
    private function enviarLote(array $lote): array
    {
        $updates = array_map(fn (array $item) => [
            'product_id' => $item['vinculo']->tn_product_id,
            'variant_id' => $item['vinculo']->variant_id,
            'stock' => $item['cantidad'],
        ], $lote);

        $respuesta = $this->cliente->escribir('update_stock_and_price', ['updates' => $updates]);

        if ($respuesta->fallo()) {
            // Fallo a nivel de todo el chunk (protocolo/red): ningún ítem del lote
            // se pudo confirmar, pero los chunks siguientes igual se intentan
            // (FR-015) — el bucle exterior sigue con el próximo array_chunk.
            $mensaje = $respuesta->mensajeError ?? 'Tiendanube rechazó la actualización.';

            foreach ($lote as $item) {
                $item['vinculo']->update(['stock_error' => $mensaje, 'stock_error_en' => now()]);
            }

            return [0, count($lote)];
        }

        // Formato de respuesta ante fallos parciales no verificado empíricamente
        // (research.md R6, T032a pendiente): se asume, por analogía con
        // `bulk_delete_products`, un resultado por ítem en `datos['results']`
        // (`variant_id` + `success`/`error`). Un ítem sin resultado explícito se
        // considera exitoso, ya que la llamada en su conjunto no falló.
        $resultadosPorItem = collect($respuesta->datos['results'] ?? [])->keyBy('variant_id');

        $actualizados = 0;
        $conError = 0;

        foreach ($lote as $item) {
            $vinculo = $item['vinculo'];
            $resultado = $resultadosPorItem->get((string) $vinculo->variant_id) ?? $resultadosPorItem->get($vinculo->variant_id);
            $exito = $resultado === null || (bool) ($resultado['success'] ?? true);

            if (! $exito) {
                $conError++;
                $vinculo->update([
                    'stock_error' => $resultado['error'] ?? $resultado['message'] ?? 'Tiendanube rechazó la actualización.',
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

        return [$actualizados, $conError];
    }
}
