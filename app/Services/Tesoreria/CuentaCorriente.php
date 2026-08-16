<?php

namespace App\Services\Tesoreria;

use App\Models\Cliente;
use App\Models\Proveedor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aging de Cuenta Corriente (Clientes/Proveedores) — cálculo mínimo para el
 * dashboard (spec 010), reutilizable después por una futura spec de Informes
 * (Cta Cte). El saldo por documento (equivalente a `Venta::aCobrar()`/
 * `Compra::aPagar()`, research.md §2) se agrega en SQL en vez de cargar todas
 * las Ventas/Compras con sus relaciones a memoria de PHP — con el volumen real
 * de datos (histórico importado) ese enfoque agotaba el `memory_limit` de
 * PHP-FPM en producción.
 */
class CuentaCorriente
{
    private const TOLERANCIA = 0.005;

    /**
     * Criterio del saldo a una fecha pasada: **se replica el de Contagram** (decisión del usuario,
     * 13/08/2026), y Contagram **no usa el mismo criterio de los dos lados**. Medido, no supuesto:
     *
     * - **Clientes**: los comprobantes se filtran por fecha de emisión, pero los cobros se aplican
     *   **completos**, sin importar cuándo ocurrieron. Verificado contra la ficha de la venta 1637
     *   (06/11/2021, $296.169,36): cobró $93.895,34 en 2021 y el resto en febrero y abril de 2022,
     *   y aun así el panel de Contagram al 31/12/2021 la da por saldada, cuando ese día debía
     *   $202.274,02. Prueba de que no puede ser un filtro por fecha: para llegar a los −$6,74 que
     *   muestra, las ventas de 2021 tendrían que haber cobrado $24.048.073,51 dentro de 2021,
     *   cuando los extractos de tesorería registran $22.871.451,47 de cobros en todo el año.
     * - **Proveedores**: acá sí respeta la fecha del pago. Con el filtro puesto, el saldo al
     *   31/12/2021 da $1.199.345,88 contra $1.194.695,87 de Contagram: **$4.650,01**, que es el
     *   mismo desfasaje constante que la bitácora §15 ya había medido en el VPS ($4.649,96) y que
     *   está entre el informe de Contagram y su propio panel, no en el CRM.
     *
     * O sea que del lado de clientes el saldo a una fecha responde "cómo quedaron los comprobantes
     * emitidos hasta ese día", y del lado de proveedores "cuánto se debía ese día". No es coherente,
     * pero es lo que ve el negocio y lo que se decidió reproducir.
     *
     * Poniendo las dos constantes en `false` se vuelve al aging contable estándar en ambos lados,
     * que es lo que hacía el código entre el 12/08 y el 13/08/2026.
     *
     * **Proveedores tiene que quedar en `false`**: probado el 16/08/2026, ponerlo en `true` aplica
     * todos los pagos sin mirar la fecha y el saldo histórico se desploma —al 12/09/2021 daba
     * $0,00 contra los $777.451,80 de Contagram—. El corte a "hoy" se resuelve aparte, con
     * {@see self::$acotarPagos} (ver ahí por qué).
     */
    private const ESTILO_CONTAGRAM = ['cliente' => true, 'proveedor' => false];

    /**
     * Clasifica un saldo en un bucket de antigüedad según `$vencimiento` (fecha
     * de referencia) comparado contra `$fecha` (hoy, salvo test). Fecha nula
     * cae en "a_vencer" (research.md R5) — mismo criterio para Venta/Compra
     * (`fecha_vto_cobro`/`fecha_vto_pago`) y para saldo inicial
     * (`saldo_inicial_fecha`), sin duplicar la lógica de corte 30/60/90 (R1).
     *
     * @return string una de: 'a_vencer', '0_30', '31_60', '61_90', 'mas_90'
     */
    private function clasificarBucket(mixed $vencimiento, Carbon $fecha): string
    {
        if ($vencimiento === null || Carbon::parse($vencimiento)->greaterThanOrEqualTo($fecha)) {
            return 'a_vencer';
        }

        $diasVencido = Carbon::parse($vencimiento)->diffInDays($fecha);

        if ($diasVencido <= 30) {
            return '0_30';
        }

        if ($diasVencido <= 60) {
            return '31_60';
        }

        if ($diasVencido <= 90) {
            return '61_90';
        }

        return 'mas_90';
    }

    /** @return Collection<int, object{saldo_inicial: string, saldo_inicial_fecha: ?string}> */
    private function entidadesConSaldoInicial(string $tipo, ?Carbon $fecha = null): Collection
    {
        $modelo = $tipo === 'cliente' ? Cliente::class : Proveedor::class;

        return $modelo::where('saldo_inicial', '!=', 0)
            // Mismo criterio de corte que los documentos: un saldo inicial fechado después no cuenta.
            ->when($fecha, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('saldo_inicial_fecha')
                ->orWhere('saldo_inicial_fecha', '<=', $fecha->toDateString())))
            ->get(['id', 'nombre', 'saldo_inicial', 'saldo_inicial_fecha']);
    }

    /**
     * Saldo por documento (Venta/Compra) agregado en SQL — mismo cálculo que
     * `Venta::aCobrar()`/`Compra::aPagar()` (Total + Σ ND − Σ NC − Cobrado/Pagado),
     * pero sin hidratar modelos Eloquent ni sus relaciones para cada fila.
     *
     * @return Collection<int, object{entidad_id: int, entidad_nombre: ?string, vencimiento: ?string, saldo: float}>
     */
    private function documentosParaAging(string $tipo): Collection
    {
        if ($tipo === 'cliente') {
            $filas = DB::table('ventas')
                ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
                ->whereNull('ventas.deleted_at')
                ->selectRaw(
                    'ventas.cliente_id as entidad_id, clientes.nombre as entidad_nombre, '.
                    'ventas.fecha_vto_cobro as vencimiento, '.
                    '(ventas.total '.
                    "+ COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.venta_id = ventas.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0) ".
                    "- COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.venta_id = ventas.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0) ".
                    '- COALESCE((SELECT SUM(c.monto) FROM cobros c WHERE c.venta_id = ventas.id AND c.deleted_at IS NULL), 0) '.
                    ') as saldo'
                )
                ->get();
        } else {
            $filas = DB::table('compras')
                ->leftJoin('proveedores', 'proveedores.id', '=', 'compras.proveedor_id')
                ->whereNull('compras.deleted_at')
                ->selectRaw(
                    'compras.proveedor_id as entidad_id, proveedores.nombre as entidad_nombre, '.
                    'compras.fecha_vto_pago as vencimiento, '.
                    '(compras.total '.
                    "+ COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0) ".
                    "- COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0) ".
                    '- COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.compra_id = compras.id AND p.deleted_at IS NULL), 0) '.
                    ') as saldo'
                )
                ->get();
        }

        return $filas->map(fn ($fila) => (object) [
            'entidad_id' => (int) $fila->entidad_id,
            'entidad_nombre' => $fila->entidad_nombre,
            'vencimiento' => $fila->vencimiento,
            'saldo' => round((float) $fila->saldo, 2),
        ]);
    }

    /**
     * Los seis buckets del aging, calculados **en una sola consulta agregada**.
     *
     * Antes se traían todos los documentos a memoria (`documentosParaAging()`) y se recorrían en
     * PHP. Con el histórico de Contagram cargado eso son 23.672 ventas, cada una con tres
     * subconsultas correlacionadas, hidratadas como objetos: la pantalla de Tesorería —que llama a
     * esto dos veces, para clientes y para proveedores— se pasaba de los 60 s de límite de
     * ejecución y devolvía error 500 (10/08/2026).
     *
     * La clasificación replica exactamente `clasificarBucket()`: sin vencimiento o vencimiento
     * futuro es `a_vencer`, y si no, se agrupa por días vencidos en 0-30, 31-60, 61-90 y >90.
     * `vencido` es la suma de todo lo que no está `a_vencer`.
     *
     * @return array<string, float>
     */
    private function bucketsEnSql(string $tipo, Carbon $fecha): array
    {
        $esCliente = $tipo === 'cliente';
        $tabla = $esCliente ? 'ventas' : 'compras';
        $vto = $esCliente ? 'fecha_vto_cobro' : 'fecha_vto_pago';
        $fk = $esCliente ? 'venta_id' : 'compra_id';
        $pagosTabla = $esCliente ? 'cobros' : 'pagos';

        $saldo = "({$tabla}.total + COALESCE(nd.monto, 0) - COALESCE(nc.monto, 0) - COALESCE(p.monto, 0))";
        // DATEDIFF y no diffInDays: la comparación tiene que resolverse en el motor, no en PHP.
        $dias = "DATEDIFF(?, {$tabla}.{$vto})";
        $aVencer = "({$tabla}.{$vto} IS NULL OR {$tabla}.{$vto} >= ?)";

        // Con el estilo Contagram los cobros/pagos y las notas NO se acotan por fecha: se aplican
        // completos sobre los comprobantes emitidos hasta el corte (ver la constante). Con el
        // criterio contable estándar sí se acotan, porque un cobro de febrero no puede reducir la
        // deuda de diciembre.
        $estiloContagram = self::ESTILO_CONTAGRAM[$tipo] ?? false;

        // Preguntar "cuánto se debe hoy" no es lo mismo que preguntar por una fecha pasada. Un
        // pago con fecha futura —un cheque diferido— ya está registrado y ya bajó la deuda: así lo
        // muestran el listado de Compras, el Dashboard de Contagram y su propio listado. Acotarlo
        // dejaba al Dashboard del CRM diciendo $27.344.772,22 de Compras a Pagar contra los
        // $25.726.465,10 de nuestro propio listado, por el cheque de Peisa fechado 24/08/2026.
        //
        // A una fecha pasada sí se acota, porque ese pago todavía no había ocurrido. Lo que NO se
        // puede hacer es resolverlo poniendo `ESTILO_CONTAGRAM['proveedor']` en `true`: eso aplica
        // todos los pagos a cualquier corte y hunde el histórico —al 12/09/2021 el saldo pasaba de
        // $777.451,78 a $0,00— (probado el 16/08/2026).
        $acotarPorFecha = ! $estiloContagram && $fecha->lt(Carbon::today());

        $sub = fn (string $t, ?string $signo, string $colFecha) => DB::table($t)
            ->selectRaw("{$fk} as fk, SUM(monto) as monto")
            ->whereNull('deleted_at')
            ->when($signo !== null, fn ($q) => $q->where('tipo', $signo))
            ->when($acotarPorFecha, fn ($q) => $q->where($colFecha, '<=', $fecha->toDateString()))
            ->whereNotNull($fk)
            ->groupBy($fk);

        $r = DB::table($tabla)
            ->leftJoinSub($sub('notas_credito_debito', 'debito', 'fecha_emision'), 'nd', 'nd.fk', '=', "{$tabla}.id")
            ->leftJoinSub($sub('notas_credito_debito', 'credito', 'fecha_emision'), 'nc', 'nc.fk', '=', "{$tabla}.id")
            ->leftJoinSub($sub($pagosTabla, null, 'fecha'), 'p', 'p.fk', '=', "{$tabla}.id")
            ->whereNull("{$tabla}.deleted_at")
            // Un comprobante emitido después del corte todavía no existía a esa fecha.
            ->where("{$tabla}.fecha_emision", '<=', $fecha->toDateString())
            ->selectRaw("
                COALESCE(SUM(CASE WHEN {$saldo} > ? AND {$aVencer} THEN {$saldo} ELSE 0 END), 0) AS a_vencer,
                COALESCE(SUM(CASE WHEN {$saldo} > ? AND NOT {$aVencer} THEN {$saldo} ELSE 0 END), 0) AS vencido,
                COALESCE(SUM(CASE WHEN {$saldo} > ? AND NOT {$aVencer} AND {$dias} <= 30 THEN {$saldo} ELSE 0 END), 0) AS b0_30,
                COALESCE(SUM(CASE WHEN {$saldo} > ? AND NOT {$aVencer} AND {$dias} > 30 AND {$dias} <= 60 THEN {$saldo} ELSE 0 END), 0) AS b31_60,
                COALESCE(SUM(CASE WHEN {$saldo} > ? AND NOT {$aVencer} AND {$dias} > 60 AND {$dias} <= 90 THEN {$saldo} ELSE 0 END), 0) AS b61_90,
                COALESCE(SUM(CASE WHEN {$saldo} > ? AND NOT {$aVencer} AND {$dias} > 90 THEN {$saldo} ELSE 0 END), 0) AS mas_90,
                COALESCE(SUM(CASE WHEN {$saldo} < ? THEN {$saldo} ELSE 0 END), 0) AS a_favor
            ", [
                self::TOLERANCIA, $fecha->toDateString(),
                self::TOLERANCIA, $fecha->toDateString(),
                self::TOLERANCIA, $fecha->toDateString(), $fecha->toDateString(),
                self::TOLERANCIA, $fecha->toDateString(), $fecha->toDateString(), $fecha->toDateString(),
                self::TOLERANCIA, $fecha->toDateString(), $fecha->toDateString(), $fecha->toDateString(),
                self::TOLERANCIA, $fecha->toDateString(), $fecha->toDateString(),
                -self::TOLERANCIA,
            ])
            ->first();

        return [
            // Un pago/cobro anterior a la emisión de su comprobante es un **anticipo**: a la fecha
            // de corte la plata ya se movió aunque la factura todavía no exista, así que es saldo a
            // favor y resta de la deuda. Sin esto el filtro por `fecha_emision` se lleva puesto el
            // pago junto con la compra futura: al 01/08/2026 un único pago a Mercado Libre de
            // $9.535.004,71 (hecho el 31/07, facturado el 04/08) inflaba Proveedores en esa cifra.
            // Es un patrón regular del proveedor, que liquida a fin de mes y factura a principios
            // del siguiente — hay 80 casos por $51,8 M.
            // Un comprobante sobrecobrado/sobrepagado es **saldo a favor**, no deja de existir: el
            // total de cuenta corriente lo netea, igual que Contagram. Va contra `a_vencer` porque
            // un saldo a favor no está vencido — los buckets de antigüedad siguen mostrando sólo
            // deuda genuina. Al 01/08/2026 son 142 ventas por −$3,5 M y 28 compras por −$272 mil.
            // Con el criterio de Contagram no se restan anticipos: un comprobante emitido después
            // del corte queda fuera del cálculo, y sus cobros con él. Restarlos contaría plata de
            // una factura que a esa fecha no existe.
            'a_vencer' => (float) $r->a_vencer + (float) $r->a_favor
                - ($estiloContagram ? 0.0 : $this->anticipos($tipo, $fecha)),
            'vencido' => (float) $r->vencido,
            '0_30' => (float) $r->b0_30,
            '31_60' => (float) $r->b31_60,
            '61_90' => (float) $r->b61_90,
            'mas_90' => (float) $r->mas_90,
        ];
    }

    /** Pagos/cobros ya hechos a la fecha de corte cuyo comprobante se emitió después. */
    private function anticipos(string $tipo, Carbon $fecha): float
    {
        $esCliente = $tipo === 'cliente';
        $tabla = $esCliente ? 'ventas' : 'compras';
        $fk = $esCliente ? 'venta_id' : 'compra_id';
        $pagosTabla = $esCliente ? 'cobros' : 'pagos';

        return (float) DB::table($pagosTabla)
            ->join($tabla, "{$tabla}.id", '=', "{$pagosTabla}.{$fk}")
            ->whereNull("{$pagosTabla}.deleted_at")
            ->whereNull("{$tabla}.deleted_at")
            ->where("{$pagosTabla}.fecha", '<=', $fecha->toDateString())
            ->where("{$tabla}.fecha_emision", '>', $fecha->toDateString())
            ->sum("{$pagosTabla}.monto");
    }

    /** @return array{total: float, buckets: array{a_vencer: float, vencido: float, "0_30": float, "31_60": float, "61_90": float, mas_90: float}} */
    public function aging(string $tipo, ?Carbon $fecha = null): array
    {
        $fecha = $fecha ?? Carbon::today();
        $buckets = $this->bucketsEnSql($tipo, $fecha);

        // Saldo inicial de Cliente/Proveedor (spec 031): a diferencia de los
        // documentos, no se descarta por `saldo <= TOLERANCIA` sino sólo si es
        // ≈0 (research.md R2) — un saldo negativo (a favor) resta del total.
        foreach ($this->entidadesConSaldoInicial($tipo, $fecha) as $entidad) {
            $saldo = (float) $entidad->saldo_inicial;

            if (abs($saldo) <= self::TOLERANCIA) {
                continue;
            }

            $bucket = $this->clasificarBucket($entidad->saldo_inicial_fecha, $fecha);
            $buckets[$bucket] += $saldo;

            if ($bucket !== 'a_vencer') {
                $buckets['vencido'] += $saldo;
            }
        }

        foreach ($buckets as $clave => $valor) {
            $buckets[$clave] = round($valor, 2);
        }

        return [
            'total' => round($buckets['a_vencer'] + $buckets['vencido'], 2),
            'buckets' => $buckets,
        ];
    }

    /**
     * Mismo aging que {@see self::aging()}, pero acumulado por cliente/proveedor
     * en vez de en un único total global (data-model.md "Vista derivada: fila
     * de Saldos Clientes"). Excluye a quienes ya no tienen saldo pendiente (FR-002).
     *
     * @return Collection<int, array{cliente_id?: int, cliente_nombre?: string, proveedor_id?: int, proveedor_nombre?: string, a_vencer: float, vencido_0_30: float, vencido_31_60: float, vencido_61_90: float, vencido_mas_90: float, total: float}>
     */
    public function porCliente(string $tipo, ?Carbon $fecha = null): Collection
    {
        $fecha = $fecha ?? Carbon::today();
        $campoEntidad = $tipo === 'cliente' ? 'cliente_id' : 'proveedor_id';
        $documentos = $this->documentosParaAging($tipo);

        $acumulado = [];

        foreach ($documentos as $documento) {
            $saldo = $documento->saldo;

            // Se descarta lo saldado (≈0), **no lo negativo**: un saldo a favor es plata que el
            // cliente ya pagó y todavía no consumió, y Contagram lo lista. Con el `<= TOLERANCIA`
            // que había acá esas filas no aparecían en ningún lado — más de 20 clientes y $1,44
            // millones invisibles, y el caso mal cargado de FLORENCIA 1159751732 pasó desapercibido
            // justo por eso. El bloque de saldo inicial de abajo ya usaba este criterio: eran dos
            // varas distintas para el mismo concepto en la misma pantalla.
            //
            // Sólo afecta al listado del informe. Los totales de Tesorería salen de `aging()`, que
            // arma sus buckets por otro camino (`bucketsEnSql`) y no pasa por acá.
            if (abs($saldo) <= self::TOLERANCIA) {
                continue;
            }

            $entidadId = $documento->entidad_id;

            if (! isset($acumulado[$entidadId])) {
                $acumulado[$entidadId] = [
                    $campoEntidad => $entidadId,
                    ($tipo === 'cliente' ? 'cliente_nombre' : 'proveedor_nombre') => $documento->entidad_nombre,
                    'a_vencer' => 0.0,
                    'vencido_0_30' => 0.0,
                    'vencido_31_60' => 0.0,
                    'vencido_61_90' => 0.0,
                    'vencido_mas_90' => 0.0,
                ];
            }

            $bucket = $this->clasificarBucket($documento->vencimiento, $fecha);
            $clave = $bucket === 'a_vencer' ? 'a_vencer' : "vencido_{$bucket}";
            $acumulado[$entidadId][$clave] += $saldo;
        }

        // Saldo inicial de Cliente/Proveedor (spec 031): crea la fila si el
        // Cliente/Proveedor todavía no tiene ninguna Venta/Compra (R3), y suma
        // con signo (positivo o negativo) sin descartar por `saldo <= TOLERANCIA`
        // — sólo si es ≈0 (research.md R2, FR-005).
        foreach ($this->entidadesConSaldoInicial($tipo) as $entidad) {
            $saldo = (float) $entidad->saldo_inicial;

            if (abs($saldo) <= self::TOLERANCIA) {
                continue;
            }

            if (! isset($acumulado[$entidad->id])) {
                $acumulado[$entidad->id] = [
                    $campoEntidad => $entidad->id,
                    ($tipo === 'cliente' ? 'cliente_nombre' : 'proveedor_nombre') => $entidad->nombre,
                    'a_vencer' => 0.0,
                    'vencido_0_30' => 0.0,
                    'vencido_31_60' => 0.0,
                    'vencido_61_90' => 0.0,
                    'vencido_mas_90' => 0.0,
                ];
            }

            $bucket = $this->clasificarBucket($entidad->saldo_inicial_fecha, $fecha);
            $clave = $bucket === 'a_vencer' ? 'a_vencer' : "vencido_{$bucket}";
            $acumulado[$entidad->id][$clave] += $saldo;
        }

        return collect($acumulado)
            ->map(function (array $fila) {
                foreach (['a_vencer', 'vencido_0_30', 'vencido_31_60', 'vencido_61_90', 'vencido_mas_90'] as $bucket) {
                    $fila[$bucket] = round($fila[$bucket], 2);
                }

                $fila['total'] = round(
                    $fila['a_vencer'] + $fila['vencido_0_30'] + $fila['vencido_31_60']
                    + $fila['vencido_61_90'] + $fila['vencido_mas_90'],
                    2
                );

                return $fila;
            })
            ->filter(fn (array $fila) => abs($fila['total']) > self::TOLERANCIA)
            ->values();
    }
}
