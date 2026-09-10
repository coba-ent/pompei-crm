<?php

namespace App\Services\Tiendanube;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use Illuminate\Support\Facades\Cache;

/**
 * Empuja hacia Tiendanube los precios de los vínculos cuyo producto cambió de
 * precio dentro de la Lista de Precios configurada (spec 018 ampliación,
 * plan.md §8), vía el cliente REST (spec 024). Contraparte de precio de
 * SincronizadorStock: mismos cortes de kill-switch (FR-032/FR-033) y misma
 * continuidad ante el rechazo de un vínculo puntual (FR-031), pero sin
 * corrida programada — el disparo es por evento (PrecioProductoObserver) o
 * manual (ejecutar()/sincronizarListaCompleta()). `PUT /products/{id}/variants/{id}`,
 * un ítem por llamada (ya era así con el MCP, research.md R4 de spec 024).
 */
class SincronizadorPrecios
{
    public const LOCK_KEY = 'tn:sincronizar_precios';

    public function __construct(
        private readonly ClienteTiendanubeRest $cliente,
    ) {
    }

    /**
     * Envía el precio vigente de un vínculo puntual. Usado directamente por
     * PrecioProductoObserver (un único vínculo) y, en bucle, por ejecutar() y
     * sincronizarListaCompleta() (que ya verificaron los cortes una sola vez
     * antes de iterar — ver verificarCortes()).
     *
     * El importe **no** entra por parámetro (spec 102, plan.md §2): lo resuelve
     * resolverPrecios() leyendo las dos listas configuradas (normal y
     * promocional) directamente del vínculo. Antes se pasaba desde el registro
     * de precio recién editado — funcionaba con una sola lista, pero con dos
     * hubiera publicado el promocional como precio de venta al editar esa
     * lista (FR-009b).
     */
    public function enviarUno(TiendanubeVarianteProducto $vinculo): bool
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

        [$precio, $promocional] = $this->resolverPrecios($vinculo);

        if ($precio === null) {
            $vinculo->update([
                'precio_error' => 'El producto no tiene precio en la Lista de Precios configurada.',
                'precio_error_en' => now(),
            ]);

            return false;
        }

        $cuerpo = ['price' => $precio];

        // FR-004: Tiendanube no valida nada — acepta un promocional más caro o
        // igual al precio de lista y lo publica igual (HTTP 200, verificado
        // contra la cuenta real). Esta comparación es la única red que hay.
        if ($promocional !== null && $promocional >= $precio) {
            $vinculo->update([
                'precio_error' => sprintf(
                    'El precio promocional ($%s) no puede ser mayor o igual al precio de lista ($%s).',
                    number_format($promocional, 2, ',', '.'),
                    number_format($precio, 2, ',', '.'),
                ),
                'precio_error_en' => now(),
            ]);

            // FR-005: el rechazo es sólo del promocional. El precio de lista se
            // envía igual — un promocional mal cargado no puede bloquear la
            // actualización del precio que efectivamente cobra la tienda.
            $promocional = null;
            $huboRechazo = true;
        } else {
            $huboRechazo = false;
        }

        // FR-002/FR-003: sin promocional el campo NO viaja (ni siquiera como
        // null). Omitirlo es lo que hace que Tiendanube conserve la promoción
        // que ya tenga cargada — verificado contra la cuenta real: mandar
        // `null` o `""` la borra, omitir la clave la deja intacta.
        if ($promocional !== null) {
            $cuerpo['promotional_price'] = (string) $promocional;
        }

        $respuesta = $this->cliente->escribir(
            'PUT',
            "products/{$vinculo->tn_product_id}/variants/{$vinculo->variant_id}",
            $cuerpo
        );

        if ($respuesta->fallo()) {
            $vinculo->update([
                'precio_error' => $respuesta->mensajeError ?? 'Tiendanube rechazó la actualización.',
                'precio_error_en' => now(),
            ]);

            return false;
        }

        if ($huboRechazo) {
            // El PUT salió bien (precio de lista actualizado), pero el error del
            // promocional rechazado tiene que quedar visible en el vínculo
            // (FR-004/FR-006): no se limpia como en el camino exitoso.
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
     * Punto único que resuelve los dos precios de un vínculo leyendo las
     * listas configuradas (spec 102, plan.md §5) — lo usan enviarUno(),
     * ejecutar() (indirectamente) y sincronizarListaCompleta(), para que no
     * haya tres lugares resolviendo lo mismo por su cuenta (el origen del bug
     * de NC/ND de la spec 099).
     *
     * @return array{0: ?float, 1: ?float} [precio de lista, precio promocional]
     */
    public function resolverPrecios(TiendanubeVarianteProducto $vinculo): array
    {
        $conexion = TiendanubeConexionRest::actual();
        $producto = $vinculo->producto;

        if (! $producto || ! $conexion->lista_precio_id) {
            return [null, null];
        }

        $precio = $producto->precios()->where('lista_precio_id', $conexion->lista_precio_id)->value('precio');

        $promocional = null;

        if ($conexion->lista_precio_promocional_id) {
            $promocional = $producto->precios()->where('lista_precio_id', $conexion->lista_precio_promocional_id)->value('precio');
        }

        return [
            $precio !== null ? (float) $precio : null,
            // Cero se trata como ausente (FR-002/T007): no hay oferta de $0.
            ($promocional !== null && (float) $promocional > 0) ? (float) $promocional : null,
        ];
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
        $conexion = TiendanubeConexionRest::actual();

        if (! $conexion->lista_precio_id) {
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
            return $this->enviarPendientes($conexion->lista_precio_id);
        } finally {
            $lock->release();
        }
    }

    /**
     * Al cambiar cuál es la Lista de Precios configurada — normal (US9,
     * FR-028) o promocional (FR-009a) —: empuja de inmediato los precios
     * vigentes a todos los vínculos que tengan precio cargado en la lista que
     * cambió. `$listaPrecioId` sólo decide **a quién** tocar; el importe que
     * viaja siempre sale de resolverPrecios(), leyendo las dos listas
     * configuradas (FR-009b) — no necesariamente la que disparó el llamado.
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

            $tienePrecio = $vinculo->producto->precios()->where('lista_precio_id', $listaPrecioId)->exists();

            if (! $tienePrecio) {
                continue;
            }

            if ($bloqueo) {
                $vinculo->update(['precio_pendiente' => true]);

                continue;
            }

            if ($this->enviarUno($vinculo)) {
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

            $tienePrecio = $vinculo->producto->precios()->where('lista_precio_id', $listaPrecioId)->exists();

            if (! $tienePrecio) {
                continue;
            }

            if ($this->enviarUno($vinculo)) {
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

        $conexion = TiendanubeConexionRest::actual();

        if ($conexion->modo_solo_lectura) {
            return 'Bloqueada por el modo sólo lectura: las escrituras hacia Tiendanube están deshabilitadas.';
        }

        if (! $conexion->estaCompleta() || $conexion->estado === EstadoConexion::Caida) {
            return 'No hay una conexión con Tiendanube establecida. Hace falta reconectar Tiendanube (soporte técnico).';
        }

        return null;
    }

    private function bloquear(string $mensaje): array
    {
        TiendanubeRestOperacionLog::registrar([
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
