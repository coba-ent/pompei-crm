<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConexion;
use App\Enums\Tiendanube\EstadoConversion;
use App\Enums\Tiendanube\MotivoRequiereAtencion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOperacionLog;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Services\Tiendanube\Excepciones\SincronizacionFallidaException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza las órdenes de venta de Tiendanube hacia `tn_ordenes` (plan.md
 * §2). Candado propio (FR-014), cortes previos a paginar (FR-017/FR-018),
 * ventana móvil re-consultada en cada corrida —no incremental real, corrección
 * post-019 (FR-013/FR-015/FR-016)— y exclusión de `storefront=meli` delegada
 * por completo a `TraductorOrdenes` (FR-012a, research.md R2 corregido).
 */
class SincronizadorOrdenes
{
    public const LOCK_KEY = 'tn:sincronizar_ordenes';

    /**
     * Nombre de parámetro de paginación verificado empíricamente para
     * `list_products` (`TiendanubeOAuthController::verificarConexion()`,
     * spec 019): `page_size`, no `limit` como asumía la primera versión de
     * esta spec (plan.md/contracts) — se sigue el patrón ya confirmado contra
     * la cuenta real en vez de un nombre nunca verificado para `list_orders`.
     */
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly ClienteTiendanube $cliente,
        private readonly TraductorOrdenes $traductor,
        private readonly EvaluadorConvertibilidad $evaluador,
        private readonly ConversorOrdenAVenta $conversor,
        private readonly ResolutorCliente $resolutorCliente,
    ) {
    }

    /**
     * @return array{ok: bool, mensaje: string, nuevas?: int, actualizadas?: int}
     */
    public function ejecutar(): array
    {
        if ($bloqueo = $this->verificarCortes()) {
            return $bloqueo;
        }

        $lock = Cache::lock(self::LOCK_KEY, 300);

        if (! $lock->get()) {
            return ['ok' => false, 'tipo' => 'salteada', 'mensaje' => 'Ya hay una sincronización en curso.'];
        }

        try {
            return $this->sincronizar();
        } catch (SincronizacionFallidaException $e) {
            return ['ok' => false, 'tipo' => 'error', 'mensaje' => $e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    /**
     * Cortes previos al bucle de paginación (FR-017/FR-018): función
     * desactivada, modo sólo lectura o conexión caída/no configurada. Un único
     * registro en el historial por corte, nunca uno por página.
     */
    private function verificarCortes(): ?array
    {
        if (! (bool) FuncionAvanzada::where('clave', 'tiendanube')->value('activa')) {
            return $this->bloquear('La función "Tiendanube" está desactivada en Funciones Avanzadas.');
        }

        $configuracion = TiendanubeConfiguracion::actual();

        if ($configuracion->modo_solo_lectura) {
            return $this->bloquear('Bloqueada por el modo sólo lectura: la sincronización está deshabilitada mientras esté activo.');
        }

        if (! $configuracion->estaCompleta() || $configuracion->estado === EstadoConexion::Caida) {
            return $this->bloquear('No hay una conexión con Tiendanube establecida. Hace falta reconectar (soporte técnico).');
        }

        return null;
    }

    private function bloquear(string $mensaje): array
    {
        TiendanubeOperacionLog::registrar([
            'operacion' => 'sincronizar_ordenes',
            'metodo' => 'POST',
            'endpoint' => '/',
            'sentido' => 'lectura',
            'resultado' => 'bloqueada',
            'usuario_id' => auth()->id(),
        ]);

        return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => $mensaje];
    }

    private function sincronizar(): array
    {
        $configuracion = TiendanubeConfiguracion::actual();

        // Corrección post-019 (FR-013/FR-016): sin `updated_at_min`, cada corrida
        // re-consulta la ventana completa de `dias_primera_sync` días, no sólo la
        // primera vez — no hay forma de pedirle a Tiendanube "sólo lo que cambió".
        $dias = (int) $configuracion->dias_primera_sync;
        $desde = now()->subDays($dias);
        $hasta = now();

        [$nuevas, $actualizadas] = $this->paginarYProcesar($desde, $hasta);

        $configuracion->update([
            'ultima_sync_en' => $hasta,
            'ultima_sync_resultado' => "OK: {$nuevas} nuevas, {$actualizadas} actualizadas (".$hasta->format('d/m/Y H:i').').',
        ]);

        return [
            'ok' => true,
            'mensaje' => "{$nuevas} órdenes nuevas, {$actualizadas} actualizadas.",
            'nuevas' => $nuevas,
            'actualizadas' => $actualizadas,
        ];
    }

    /**
     * @return array{0: int, 1: int} [nuevas, actualizadas]
     */
    private function paginarYProcesar(Carbon $desde, Carbon $hasta): array
    {
        $nuevas = 0;
        $actualizadas = 0;
        $pagina = 1;

        do {
            $respuesta = $this->cliente->leer('list_orders', [
                'status' => ['open', 'closed', 'cancelled'],
                'completed_at_from' => $desde->toIso8601String(),
                'completed_at_to' => $hasta->toIso8601String(),
                'page' => $pagina,
                'page_size' => self::PAGE_SIZE,
            ]);

            if ($respuesta->fallo()) {
                throw new SincronizacionFallidaException($respuesta->mensajeError ?? 'No se pudo sincronizar con Tiendanube.');
            }

            // Nombre de la clave de resultado ("orders") sin verificar contra la
            // tool real (sólo se verificó empíricamente `pagination.total_elements`
            // de `list_products`, spec 019 R4) — a confirmar contra la cuenta real
            // en el primer "Sincronizar ahora"; retomar sin reprocesar (FR-015) sale
            // gratis acá porque cada página se persiste antes de pedir la siguiente.
            $ordenesCrudas = $respuesta->datos['orders'] ?? [];

            foreach ($ordenesCrudas as $ordenCruda) {
                $esNueva = $this->procesarOrden($ordenCruda);

                if ($esNueva === null) {
                    continue; // FR-012a: storefront=meli, descartada por completo.
                }

                $esNueva ? $nuevas++ : $actualizadas++;
            }

            $cantidad = count($ordenesCrudas);
            $pagina++;
        } while ($cantidad === self::PAGE_SIZE);

        return [$nuevas, $actualizadas];
    }

    /** Upsert por `tn_order_id` (FR-013) + estado de conversión derivado (FR-007a). Null si se descarta (FR-012a). */
    private function procesarOrden(array $ordenCruda): ?bool
    {
        $datosOrden = $this->traductor->traducirOrden($ordenCruda);

        if ($datosOrden === null) {
            return null;
        }

        $itemsTraducidos = $this->traductor->traducirItems($ordenCruda);

        [$esNueva, $orden, $estado] = DB::transaction(function () use ($datosOrden, $itemsTraducidos) {
            $existente = TiendanubeOrden::where('tn_order_id', $datosOrden['tn_order_id'])->first();
            $esNueva = ! $existente;
            $estadoPrevio = $existente?->estado_conversion ?? EstadoConversion::PendientePago;

            $orden = TiendanubeOrden::updateOrCreate(
                ['tn_order_id' => $datosOrden['tn_order_id']],
                array_merge($datosOrden, [
                    'estado_conversion' => $estadoPrevio->value,
                    'sincronizada_en' => now(),
                ])
            );

            // Los ítems de una orden ya convertida no se retocan: producto_id es un
            // snapshot histórico de con qué se convirtió (FR-062).
            if (! $orden->venta_id) {
                $orden->items()->delete();

                foreach ($itemsTraducidos as $itemData) {
                    $vinculo = TiendanubeVarianteProducto::where('variant_id', $itemData['variant_id'])->first();
                    $itemData['producto_id'] = $vinculo?->producto_id;
                    $orden->items()->create($itemData);
                }

                $orden->load('items');
            }

            ['cliente' => $clienteExistente, 'ambiguo' => $clienteAmbiguo] =
                $this->resolutorCliente->buscarExistente($orden);

            [$estado, $motivo, $detalle] = $this->evaluador->evaluar($orden, $clienteAmbiguo);

            $orden->update([
                'estado_conversion' => $estado->value,
                'motivo' => $motivo?->value,
                'motivo_detalle' => $detalle,
            ]);

            unset($clienteExistente);

            return [$esNueva, $orden, $estado];
        });

        // US5 (FR-051): fuera de la transacción de sincronización — ConversorOrdenAVenta
        // maneja su propio candado y su propia transacción atómica (FR-048).
        if ($estado === EstadoConversion::Lista && TiendanubeConfiguracion::actual()->creacion_automatica) {
            $this->intentarCreacionAutomatica($orden);
        }

        return $esNueva;
    }

    /** FR-055: ante un fallo inesperado, la orden queda con el motivo y el error registrado, sin Venta parcial. */
    private function intentarCreacionAutomatica(TiendanubeOrden $orden): void
    {
        try {
            $this->conversor->convertir($orden, null, automatica: true);
        } catch (\Throwable $e) {
            $orden->update([
                'estado_conversion' => EstadoConversion::RequiereAtencion->value,
                'motivo' => MotivoRequiereAtencion::ErrorConversion->value,
                'motivo_detalle' => 'Falla inesperada durante la creación automática: '.$e->getMessage(),
            ]);
        }
    }
}
