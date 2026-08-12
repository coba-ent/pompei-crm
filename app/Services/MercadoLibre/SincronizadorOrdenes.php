<?php

namespace App\Services\MercadoLibre;

use App\Enums\MercadoLibre\EstadoConversion;
use App\Enums\MercadoLibre\MotivoRequiereAtencion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Services\MercadoLibre\Excepciones\SincronizacionFallidaException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza las órdenes de venta de Mercado Libre hacia `ml_ordenes`
 * (spec 012, plan.md §1). Candado global (FR-014), cortes previos a paginar
 * (FR-017/FR-018, research.md §R10), incremental con solapamiento (FR-013,
 * research.md §R4) y segunda pasada obligatoria de canceladas (FR-012a,
 * research.md §R2).
 */
class SincronizadorOrdenes
{
    public const LOCK_KEY = 'ml:sincronizar_ordenes';

    private const LIMIT = 50;

    private const SOLAPAMIENTO_MINUTOS = 15;

    private const TOPE_DIAS_PRIMERA_SYNC = 365;

    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
        private readonly TraductorOrdenes $traductor,
        private readonly EvaluadorConvertibilidad $evaluador,
        private readonly ConversorOrdenAVenta $conversor,
        private readonly ResolutorCliente $resolutorCliente,
        private readonly DetectorCancelaciones $detectorCancelaciones,
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
        if (! (bool) FuncionAvanzada::where('clave', 'mercadolibre')->value('activa')) {
            return $this->bloquear('La función "Mercado Libre" está desactivada en Funciones Avanzadas.');
        }

        if (MercadoLibreConfiguracion::actual()->modo_solo_lectura) {
            return $this->bloquear('Bloqueada por el modo sólo lectura: la sincronización está deshabilitada mientras esté activo.');
        }

        if (! MercadoLibreCuenta::conectada()->exists()) {
            return $this->bloquear('No hay ninguna cuenta de Mercado Libre conectada. Volvé a conectar la cuenta.');
        }

        return null;
    }

    private function bloquear(string $mensaje): array
    {
        MercadoLibreOperacionLog::registrar([
            'operacion' => 'sincronizar_ordenes',
            'metodo' => 'GET',
            'endpoint' => '/orders/search',
            'sentido' => 'lectura',
            'resultado' => 'bloqueada',
            'usuario_id' => auth()->id(),
        ]);

        return ['ok' => false, 'tipo' => 'bloqueada', 'mensaje' => $mensaje];
    }

    private function sincronizar(): array
    {
        $configuracion = MercadoLibreConfiguracion::actual();
        $cuenta = MercadoLibreCuenta::conectada()->first();

        if (! $cuenta) {
            throw new SincronizacionFallidaException('No hay ninguna cuenta de Mercado Libre conectada.');
        }

        $dias = min((int) $configuracion->dias_primera_sync, self::TOPE_DIAS_PRIMERA_SYNC);
        $desde = $configuracion->ultima_sync_en
            ? $configuracion->ultima_sync_en->copy()->subMinutes(self::SOLAPAMIENTO_MINUTOS)
            : now()->subDays($dias);
        $hasta = now();

        [$nuevasNormales, $actualizadasNormales] = $this->paginarYProcesar($cuenta, $desde, $hasta, soloCanceladas: false);
        [$nuevasCanceladas, $actualizadasCanceladas] = $this->paginarYProcesar($cuenta, $desde, $hasta, soloCanceladas: true);

        $nuevas = $nuevasNormales + $nuevasCanceladas;
        $actualizadas = $actualizadasNormales + $actualizadasCanceladas;

        // El avance sólo se persiste al terminar AMBAS pasadas completas: si la
        // corrida se interrumpe a mitad de camino, la próxima repite la misma
        // ventana en lugar de arriesgar perder órdenes de la pasada de
        // canceladas que no llegó a correr (research.md §R4 — sin este orden,
        // adelantar la marca por página podría dejar canceladas sin traer).
        $configuracion->update([
            'ultima_sync_en' => $hasta,
            'ultima_sync_resultado' => "OK: {$nuevas} nuevas, {$actualizadas} actualizadas (".$hasta->local()->format('d/m/Y H:i').').',
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
    private function paginarYProcesar(MercadoLibreCuenta $cuenta, Carbon $desde, Carbon $hasta, bool $soloCanceladas): array
    {
        $nuevas = 0;
        $actualizadas = 0;
        $offset = 0;

        do {
            $query = [
                'seller' => $cuenta->ml_user_id,
                'order.date_last_updated.from' => $desde->toIso8601String(),
                'order.date_last_updated.to' => $hasta->toIso8601String(),
                'sort' => 'date_asc',
                'offset' => $offset,
                'limit' => self::LIMIT,
            ];

            if ($soloCanceladas) {
                $query['order.status'] = 'cancelled';
            }

            $respuesta = $this->cliente->obtener('sincronizar_ordenes', '/orders/search', $query);

            if ($respuesta->fallo()) {
                throw new SincronizacionFallidaException($respuesta->mensajeError ?? 'No se pudo sincronizar con Mercado Libre.');
            }

            $datosFaltantes = $respuesta->esParcial() ? $respuesta->contenidoFaltante() : null;

            foreach ($respuesta->datos['results'] ?? [] as $ordenCruda) {
                if ($this->procesarOrden($ordenCruda, $datosFaltantes)) {
                    $nuevas++;
                } else {
                    $actualizadas++;
                }
            }

            $total = (int) ($respuesta->datos['paging']['total'] ?? 0);
            $offset += self::LIMIT;
        } while ($offset < $total);

        return [$nuevas, $actualizadas];
    }

    /** Upsert por `ml_order_id` (FR-013/FR-014) + estado de conversión derivado (FR-007a). Devuelve true si fue alta. */
    private function procesarOrden(array $ordenCruda, ?string $datosFaltantes): bool
    {
        $datosOrden = $this->traductor->traducirOrden($ordenCruda, $datosFaltantes);
        $itemsTraducidos = $this->traductor->traducirItems($ordenCruda);

        [$esNueva, $orden, $estado] = DB::transaction(function () use ($datosOrden, $itemsTraducidos, $ordenCruda) {
            $existente = MercadoLibreOrden::where('ml_order_id', $datosOrden['ml_order_id'])->first();
            $esNueva = ! $existente;
            $estadoPrevio = $existente?->estado_conversion ?? EstadoConversion::PendientePago;
            // spec 063: se capturan ANTES de que evaluador->evaluar() los pise, porque para una
            // orden convertida y cancelada evaluador siempre devuelve motivo=null (líneas de
            // abajo) — sin esto el detector de cancelaciones perdería su propia marca anterior en
            // cada re-sincronización y rompería la idempotencia (FR-005).
            $motivoPrevio = $existente?->motivo;
            $motivoDetallePrevio = $existente?->motivo_detalle;

            $orden = MercadoLibreOrden::updateOrCreate(
                ['ml_order_id' => $datosOrden['ml_order_id']],
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
                    $vinculo = MercadoLibrePublicacionProducto::where('ml_item_id', $itemData['ml_item_id'])->first();
                    $itemData['producto_id'] = $vinculo?->producto_id;
                    $orden->items()->create($itemData);
                }

                $orden->load('items');
            }

            // Se resuelve el comprador SIN crear nada, para dos cosas: marcar el
            // aviso no bloqueante `cliente_nuevo` (se dará de alta un Cliente al
            // convertir) y detectar la ambigüedad ya en la sincronización, en vez
            // de recién al intentar convertir.
            ['cliente' => $clienteExistente, 'ambiguo' => $clienteAmbiguo] =
                $this->resolutorCliente->buscarExistente($orden);

            [$estado, $motivo, $detalle] = $this->evaluador->evaluar($orden, $clienteAmbiguo);

            // spec 063 (T007-T010): si la orden ya está convertida en Venta, el detector de
            // cancelaciones tiene la última palabra sobre estado/motivo — puede pisar la decisión
            // "Cancelada sin aviso" de arriba con "RequiereAtencion" + el motivo correspondiente,
            // o dejar pasar la de evaluador si no hay nada que avisar (FR-001 a FR-007).
            if ($orden->venta_id) {
                $decision = $this->detectorCancelaciones->decidir(
                    $orden, $ordenCruda, $motivoPrevio, $motivoDetallePrevio, $estadoPrevio
                );

                if ($decision) {
                    [$estado, $motivo, $detalle] = $decision;
                }
            }

            $orden->update([
                'estado_conversion' => $estado->value,
                'motivo' => $motivo?->value,
                'motivo_detalle' => $detalle,
                'cliente_nuevo' => ! $clienteExistente && ! $clienteAmbiguo,
            ]);

            return [$esNueva, $orden, $estado];
        });

        // US5 (FR-051): fuera de la transacción de sincronización — ConversorOrdenAVenta
        // maneja su propio candado y su propia transacción atómica (FR-048).
        if ($estado === EstadoConversion::Lista && MercadoLibreConfiguracion::actual()->creacion_automatica) {
            $this->intentarCreacionAutomatica($orden);
        }

        return $esNueva;
    }

    /** FR-055: ante un fallo inesperado, la orden queda con el motivo y el error registrado, sin Venta parcial. */
    private function intentarCreacionAutomatica(MercadoLibreOrden $orden): void
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
