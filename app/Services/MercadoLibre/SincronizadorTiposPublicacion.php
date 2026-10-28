<?php

namespace App\Services\MercadoLibre;

use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use Illuminate\Support\Facades\Cache;

/**
 * Mantiene al día `listing_type_id` de cada vínculo (spec 050, research.md
 * R1/R3/R4): consulta `GET /items?ids=...` en chunks de hasta 20 (límite de
 * la API de ML).
 *
 * spec 116: corre dentro de la corrida de stock (`sincronizarSiCorresponde()`), a lo sumo una vez por
 * `frecuencia_sync_minutos`, y detecta los cambios Clásica ↔ Premium hechos desde Mercado Libre: esos
 * no se escriben con el resto, sino que pasan por `ProcesadorCambioTipoDetectado`, que les publica el
 * precio de su lista nueva. Si en ese momento no se puede enviar precio, el tipo no se consolida y la
 * próxima revisión lo vuelve a detectar (FR-008).
 *
 * Ante fallo de un chunk o de un ítem puntual, los vínculos afectados
 * conservan su último `listing_type_id` conocido — no se abortan los demás
 * chunks ni se pisa el dato con `null` (Edge Case de la spec).
 */
class SincronizadorTiposPublicacion
{
    private const TAMANO_CHUNK = 20;

    /** Candado propio: la revisión del cron y la manual no se pisan entre sí. */
    public const LOCK_KEY = 'ml:sincronizar_tipos';

    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
        private readonly SincronizadorPrecios $sincronizadorPrecios,
        private readonly ProcesadorCambioTipoDetectado $procesador,
    ) {
    }

    /**
     * Lo que llama el comando de stock en cada corrida (spec 116, FR-012). Su propio freno es
     * imprescindible: con el stock bloqueado, `stock_ultima_sync_en` no avanza y el comando de stock
     * entra cada minuto. Devuelve null si no correspondía revisar.
     */
    public function sincronizarSiCorresponde(): ?array
    {
        $configuracion = MercadoLibreConfiguracion::actual();
        $ultima = $configuracion->tipo_publicacion_ultima_sync_en;

        if ($ultima && $ultima->diffInMinutes(now()) < $configuracion->frecuencia_sync_minutos) {
            return null;
        }

        // Con la función desactivada el cliente bloquea también las lecturas (FR-005b de spec 012): ni
        // se intenta, para no dejar una línea de bloqueo por tanda en cada corrida. El modo sólo
        // lectura sí deja leer; ahí lo que se posterga es el envío de precio (FR-010a).
        if (! FuncionAvanzada::where('clave', 'mercadolibre')->value('activa') || ! MercadoLibreCuenta::conectada()->exists()) {
            return null;
        }

        $lock = Cache::lock(self::LOCK_KEY, 300);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->sincronizarTodos();
        } finally {
            $lock->release();
        }
    }

    /**
     * Recorre TODOS los vínculos (no sólo los sin clasificar), cualquiera sea su estado en ML: misma
     * operación sirve de backfill inicial y de refresco periódico (R4).
     *
     * @return array{actualizados: int, con_error: int, cambios_detectados: int, cambios_procesados: int, cambios_postergados: int}
     */
    public function sincronizarTodos(): array
    {
        $actualizados = 0;
        $conError = 0;
        $detectados = [];

        MercadoLibrePublicacionProducto::query()
            ->select(['id', 'ml_item_id', 'listing_type_id'])
            ->orderBy('id')
            ->chunk(self::TAMANO_CHUNK, function ($vinculos) use (&$actualizados, &$conError, &$detectados): void {
                $resultado = $this->consultarYPersistir($vinculos->pluck('listing_type_id', 'ml_item_id')->all());
                $actualizados += $resultado['actualizados'];
                $conError += $resultado['con_error'];
                $detectados += $resultado['detectados'];
            });

        // Recién acá, con todas las consultas hechas, se toma el candado de precios: tenerlo durante
        // el multiget frenaría al observer de precios y a la confirmación de la spec 115 (research R4).
        [$procesados, $postergados] = $this->procesarDetectados($detectados);

        MercadoLibreConfiguracion::actual()->update(['tipo_publicacion_ultima_sync_en' => now()]);

        return [
            'actualizados' => $actualizados + $procesados,
            'con_error' => $conError,
            'cambios_detectados' => count($detectados),
            'cambios_procesados' => $procesados,
            'cambios_postergados' => $postergados,
        ];
    }

    /**
     * Clasifica un único vínculo recién creado.
     *
     * ⚠️ spec 065/T007a: **nadie lo invoca**. El único punto que crea vínculos es
     * `VinculadorAutomatico`, que resuelve `listing_type_id`, `logistic_type` e
     * `inventory_id` del multiget que ya hace de por sí, sin una llamada extra por
     * publicación. Se conserva por si hace falta reclasificar un vínculo suelto a mano.
     */
    public function sincronizarUno(MercadoLibrePublicacionProducto $vinculo): bool
    {
        $resultado = $this->consultarYPersistir([$vinculo->ml_item_id => $vinculo->listing_type_id]);
        [$procesados] = $this->procesarDetectados($resultado['detectados']);

        return $resultado['actualizados'] + $procesados > 0;
    }

    /**
     * @param  array<string, ?string>  $tiposGuardados  ml_item_id => listing_type_id guardado
     * @return array{actualizados: int, con_error: int, detectados: array<string, string>}
     */
    private function consultarYPersistir(array $tiposGuardados): array
    {
        $mlItemIds = array_keys($tiposGuardados);

        if (! $mlItemIds) {
            return ['actualizados' => 0, 'con_error' => 0, 'detectados' => []];
        }

        $respuesta = $this->cliente->obtener('sincronizar_tipo_publicacion', '/items', ['ids' => implode(',', $mlItemIds)]);

        if ($respuesta->fallo()) {
            return ['actualizados' => 0, 'con_error' => count($mlItemIds), 'detectados' => []];
        }

        $actualizados = 0;
        $conError = 0;
        $detectados = [];

        foreach ($respuesta->datos as $entrada) {
            if (($entrada['code'] ?? null) !== 200) {
                $conError++;

                continue;
            }

            $itemId = $entrada['body']['id'] ?? null;
            $listingTypeId = $entrada['body']['listing_type_id'] ?? null;

            if (! $itemId || ! $listingTypeId) {
                $conError++;

                continue;
            }

            // spec 065/FR-001: el tipo de logística y el inventario viajan en ESTE mismo body,
            // así que clasificar Full no cuesta ninguna llamada nueva (research R8). Igual que
            // con listing_type_id, un campo ausente conserva el último valor conocido en vez de
            // pisarse con null (FR-004): una publicación mal clasificada como no-Full recibiría
            // stock que Mercado Libre no puede escribir.
            $logisticType = $entrada['body']['shipping']['logistic_type'] ?? null;
            $inventoryId = $entrada['body']['inventory_id'] ?? null;
            // spec 065: el stock propio de una publicación Full se escribe contra el
            // "user product", así que su id se persiste acá y no cuesta una llamada extra.
            $userProductId = $entrada['body']['user_product_id'] ?? null;

            $campos = array_filter([
                'logistic_type' => $logisticType,
                'inventory_id' => $inventoryId,
                'user_product_id' => $userProductId,
                'logistica_sincronizada_en' => $logisticType ? now() : null,
            ], static fn ($valor) => $valor !== null);

            // spec 116: un Clásica ↔ Premium hecho en ML no se escribe acá — el tipo sólo se consolida
            // junto con su precio, en procesarDetectados().
            if (ProcesadorCambioTipoDetectado::esCambioDetectado($tiposGuardados[$itemId] ?? null, $listingTypeId)) {
                $detectados[$itemId] = $listingTypeId;
            } else {
                $campos += ['listing_type_id' => $listingTypeId, 'listing_type_sincronizado_en' => now()];
            }

            if ($campos === []) {
                continue;
            }

            $filas = MercadoLibrePublicacionProducto::where('ml_item_id', $itemId)->update($campos);

            if (! isset($detectados[$itemId])) {
                $actualizados += $filas;
            }
        }

        return ['actualizados' => $actualizados, 'con_error' => $conError, 'detectados' => $detectados];
    }

    /**
     * Publica el precio de cada cambio detectado, con el candado de precios tomado una sola vez. Si hay
     * un corte de escritura o el candado está tomado, no se toca ninguno: quedan postergados para la
     * próxima revisión, con una sola línea en el log (no una por vínculo).
     *
     * @param  array<string, string>  $detectados  ml_item_id => tipo vigente en ML
     * @return array{0: int, 1: int} procesados, postergados
     */
    private function procesarDetectados(array $detectados): array
    {
        if ($detectados === []) {
            return [0, 0];
        }

        $motivo = $this->sincronizadorPrecios->motivoBloqueoEscritura();
        $lock = $motivo === null ? Cache::lock(SincronizadorPrecios::LOCK_KEY, 300) : null;

        if ($lock === null || ! $lock->get()) {
            MercadoLibreOperacionLog::registrar([
                'operacion' => 'sincronizar_tipo_publicacion',
                'metodo' => 'PUT',
                'endpoint' => '/items/{id}',
                'sentido' => 'escritura',
                'resultado' => 'bloqueada',
                'mensaje_error' => count($detectados).' cambios de tipo hechos en Mercado Libre quedan para la próxima revisión: '
                    .($motivo ?? 'hay otra sincronización de precios en curso.'),
            ]);

            return [0, count($detectados)];
        }

        try {
            $procesados = 0;

            $vinculos = MercadoLibrePublicacionProducto::with('producto')->whereIn('ml_item_id', array_keys($detectados))->get();

            foreach ($vinculos as $vinculo) {
                $tipoVigente = $detectados[$vinculo->ml_item_id];

                // Puede haberlo cambiado otro camino entre el multiget y acá.
                if (! ProcesadorCambioTipoDetectado::esCambioDetectado($vinculo->listing_type_id, $tipoVigente)) {
                    continue;
                }

                $this->procesador->procesar($vinculo, $tipoVigente);
                $procesados++;
            }

            return [$procesados, 0];
        } finally {
            $lock->release();
        }
    }
}
