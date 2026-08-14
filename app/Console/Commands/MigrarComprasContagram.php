<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\NotaCreditoDebito;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\TipoProducto;
use App\Services\Migracion\ComprasContagram;
use App\Services\Migracion\CuentasDeTesoreria;
use App\Services\Migracion\LectorExcelContagram;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migra el histórico de compras de Contagram (2021 → 05/08/2026), con sus ítems, notas y pagos.
 *
 * Gemelo de `migracion:ventas` y con las mismas garantías, que son las que hacen seguro correrlo:
 *
 * - **No mueve stock.** Esa mercadería ya entró y está en el saldo actual de cada producto;
 *   sumarla de nuevo duplicaría seis años de compras. `CompraObserver` tiene el guardarraíl del
 *   otro lado (no descuenta al borrar una compra con `legacy_id`).
 * - **No genera movimientos de tesorería.** Los pagos ya salieron de la caja.
 * - **Idempotente** por `legacy_id`, que además queda como dato de consulta permanente: es el
 *   número con el que el proveedor identifica el comprobante en papel.
 * - **`created_at` = fecha del comprobante**, para que los listados no se llenen de 2.500 compras
 *   viejas arriba de las del día.
 */
class MigrarComprasContagram extends Command
{
    protected $signature = 'migracion:compras
        {--dry-run : No escribe nada; sólo reporta}
        {--anio= : Procesar un solo año}
        {--sin-pagos : No crea los pagos; se importan del informe de Movimientos de Proveedores}
        {--preservar-id : Usa el Id de Contagram como `compras.id` (sólo en una base nueva y vacía)}
        {--extra-item=* : Archivos por-ítem extra (tramo final de 2026)}
        {--corte= : Fecha de corte (por defecto 2026-08-05)}
        {--extra-fechas-directas : Los archivos de --extra-item traen la fecha bien (no invertir)}';

    protected $description = 'Migra las compras históricas de Contagram 2021-2026 (no mueve stock)';

    private array $stats = [];
    private array $proveedores = [];
    private array $categorias = [];
    private array $productos = [];
    private CuentasDeTesoreria $cuentas;
    private array $tiposProducto = [];
    private array $timestamps = [];

    /**
     * Ver `--sin-pagos`. El pago que arma este comando sale del `Pagado` acumulado de la compra:
     * queda uno solo, fechado con la emisión del comprobante y asignado al primer medio de la
     * lista. Es el mismo defecto que tenían los cobros y que hacía envejecer mal la Cuenta
     * Corriente. El informe de Movimientos de Proveedores trae el desglose real, con fecha y medio
     * por pago, así que en la base nueva conviene saltearlos acá e importarlos de ahí.
     */
    private bool $sinPagos = false;

    /** Ver `--preservar-id`: sólo en una base donde nadie más asignó ids de compra. */
    private bool $preservarId = false;


    public function handle(LectorExcelContagram $lector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->sinPagos = (bool) $this->option('sin-pagos');
        $this->preservarId = (bool) $this->option('preservar-id');
        $extra = [];
        foreach ((array) $this->option('extra-item') as $patron) {
            $extra = array_merge($extra, glob($patron) ?: (is_file($patron) ? [$patron] : []));
        }
        if ($extra !== []) {
            $this->info('Archivos extra: '.count($extra));
        }

        $servicio = new ComprasContagram($lector, public_path('imports'), $extra,
            (bool) $this->option('extra-fechas-directas'));

        if ($corte = $this->option('corte')) {
            $servicio->conCorte($corte);
            $this->info("Corte: {$corte}");
        }

        if ($this->preservarId && ($propias = Compra::whereNull('legacy_id')->count()) > 0) {
            $this->error("--preservar-id: hay {$propias} compras propias del CRM (sin legacy_id), sus ids pueden chocar.");

            return self::FAILURE;
        }
        $anios = $this->option('anio') ? [$this->option('anio')] : ComprasContagram::ANIOS;

        $this->stats = array_fill_keys([
            'compras_creadas', 'ya_existian', 'items_creados', 'nc_creadas', 'nd_creadas',
            'pagos_creados', 'fuera_de_corte', 'proveedores_creados', 'productos_legacy_creados',
            'renglones_libres',
        ], 0);

        $this->info($dryRun ? '— DRY RUN: no se escribe nada —' : '— IMPORTANDO COMPRAS —');

        $this->proveedores = Proveedor::pluck('id', 'nombre')
            ->mapWithKeys(fn ($id, $n) => [preg_replace('/\s+/u', ' ', trim($n)) => $id])->all();
        $this->categorias = Categoria::where('tipo', 'compra')->pluck('id', 'nombre')->all();
        $this->productos = Producto::whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
        $this->cuentas = new CuentasDeTesoreria();

        $totales = ['FC' => 0.0, 'NC' => 0.0, 'ND' => 0.0, 'pagado' => 0.0];

        foreach ($anios as $anio) {
            $this->line("Procesando {$anio}…");
            $comprobantes = $servicio->delAnio($anio);
            $this->timestamps = $this->calcularTimestamps($comprobantes);

            $barra = $this->output->createProgressBar(count($comprobantes));
            foreach ($comprobantes as $legacyId => $c) {
                $barra->advance();

                if (! $servicio->dentroDelCorte($c['fecha_emision'])) {
                    $this->stats['fuera_de_corte']++;

                    continue;
                }

                $totales[$c['familia']] += $c['total'];
                if ($c['familia'] === 'FC') {
                    $totales['pagado'] += $c['pagado'];
                }

                $c['familia'] === 'FC'
                    ? $this->importarCompra($c, $dryRun)
                    : $this->importarNota($c, $dryRun);
            }
            $barra->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->table(['Concepto', 'Cantidad'], collect($this->stats)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), number_format($v)])->values()->all());
        $this->table(['Importe', 'Migrado'], [
            ['Comprado', number_format($totales['FC'], 2)],
            ['Pagado', number_format($totales['pagado'], 2)],
            ['Notas de crédito', number_format(abs($totales['NC']), 2)],
            ['Notas de débito', number_format($totales['ND'], 2)],
        ]);

        if ($dryRun) {
            $this->info('DRY RUN: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    private function importarCompra(array $c, bool $dryRun): void
    {
        if (Compra::withTrashed()->where('legacy_id', $c['legacy_id'])->exists()) {
            $this->stats['ya_existian']++;

            return;
        }

        $this->stats['compras_creadas']++;
        $this->stats['items_creados'] += count($c['items']);

        if ($dryRun) {
            foreach ($c['items'] as $item) {
                $this->producto($item, $dryRun);
            }
            $this->proveedor($c['proveedor'], $dryRun);
            if (! $this->sinPagos && abs($c['pagado']) > 0.005) {
                $this->stats['pagos_creados']++;
            }

            return;
        }

        DB::transaction(function () use ($c) {
            $ts = $this->timestamps[$c['legacy_id']] ?? $c['fecha_emision'];

            $compra = new Compra([
                'legacy_id' => $c['legacy_id'],
                'proveedor_id' => $this->proveedor($c['proveedor'], false),
                'categoria_id' => $this->categoria($c['categoria']),
                'fecha_emision' => $c['fecha_emision'],
                'fecha_vto_pago' => $c['fecha_vto_pago'],
                'servicio_desde' => $c['servicio_desde'],
                'servicio_hasta' => $c['servicio_hasta'],
                'tipo_comprobante' => $c['tipo'],
                'nro_comprobante' => $this->nroComprobante($c),
                'subtotal_sin_descuento' => $c['subtotal_sin_descuento'],
                'descuento' => $c['descuento'],
                'subtotal_con_descuento' => $c['subtotal_con_descuento'],
                'total' => $c['total'],
                'nota_interna' => $c['nota_interna'],
            ]);
            $compra->created_at = $ts;
            $compra->updated_at = $ts;
            if ($this->preservarId) {
                // Igual que en ventas: el `Id` de las compras es una serie global y correlativa.
                // Verificado sobre el informe de Movimientos de Proveedores, que trae los 6 años en
                // un solo archivo: 2.386 Ids distintos y **cero** repetidos entre años (max 2.429).
                // Las NC/ND no lo preservan porque comparten tabla con las de venta.
                $compra->id = (int) $c['id_excel'];
            }
            $compra->save();

            foreach ($c['items'] as $item) {
                $ci = new CompraItem([
                    'compra_id' => $compra->id,
                    'producto_id' => $this->producto($item, false),
                    'descripcion' => $item['descripcion'],
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'iva_pct' => $item['iva_pct'],
                    'subtotal' => $item['subtotal'],
                    'subtotal_con_iva' => $item['subtotal_con_iva'],
                ]);
                $ci->created_at = $ts;
                $ci->updated_at = $ts;
                $ci->save();
            }

            // El pago va acá y no en un comando aparte: a diferencia de los cobros —que se imputan
            // por cliente y necesitaban las ventas ya cargadas—, el `Pagado` viene en la misma fila
            // de la compra, así que separarlos obligaría a releer los Excel sin ganar nada.
            if (! $this->sinPagos && abs($c['pagado']) > 0.005) {
                $medio = $c['medios_pago'][0] ?? null;
                $pago = new Pago([
                    'compra_id' => $compra->id,
                    'fecha' => $c['fecha_emision'],
                    'cuenta_tesoreria_id' => $this->cuentas->resolver($medio),
                    'monto' => $c['pagado'],
                    'nota' => 'Migrado de Contagram'.($medio ? " ({$medio})" : ''),
                ]);
                $pago->created_at = $ts;
                $pago->updated_at = $ts;
                $pago->save();
                $this->stats['pagos_creados']++;
            }
        });
    }

    /** NC/ND de compra: sin `compra_id` porque el export no dice a qué comprobante corrigen. */
    private function importarNota(array $c, bool $dryRun): void
    {
        if (NotaCreditoDebito::withTrashed()->where('legacy_id', $c['legacy_id'])->exists()) {
            return;
        }

        $this->stats[$c['familia'] === 'NC' ? 'nc_creadas' : 'nd_creadas']++;

        if ($dryRun) {
            return;
        }

        $ts = $this->timestamps[$c['legacy_id']] ?? $c['fecha_emision'];
        $nota = new NotaCreditoDebito([
            'legacy_id' => $c['legacy_id'],
            'venta_id' => null,
            'compra_id' => null,
            'tipo' => $c['familia'] === 'NC' ? 'credito' : 'debito',
            'afecta_stock' => false,
            'fecha_emision' => $c['fecha_emision'],
            'mes_imputacion' => $c['fecha_emision']?->startOfMonth(),
            // El signo lo lleva `tipo`; guardar el negativo lo restaría dos veces.
            'monto' => abs($c['total']),
            'tipo_comprobante' => $c['tipo'] ?? 'A',
            'descripcion' => $c['nota_interna'],
        ]);
        $nota->created_at = $ts;
        $nota->updated_at = $ts;
        $nota->save();
    }

    /** Ver MigrarVentasContagram::calcularTimestamps — mismo criterio y mismo motivo. */
    private function calcularTimestamps(array $comprobantes): array
    {
        $porDia = [];
        foreach ($comprobantes as $legacyId => $c) {
            if ($c['fecha_emision'] !== null) {
                $porDia[$c['fecha_emision']->toDateString()][] = [$legacyId, (int) $c['id_excel']];
            }
        }

        $ts = [];
        foreach ($porDia as $dia => $delDia) {
            usort($delDia, fn ($a, $b) => $a[1] <=> $b[1]);
            $apertura = CarbonImmutable::parse($dia)->setTime(9, 0);
            $total = count($delDia);

            foreach ($delDia as $i => [$legacyId, $_]) {
                $ts[$legacyId] = $apertura->addSeconds($total > 1 ? (int) round($i * 9 * 3600 / $total) : 0);
            }
        }

        return $ts;
    }

    private function nroComprobante(array $c): ?string
    {
        if (! $c['punto_venta'] || ! $c['nro_factura']) {
            return null;
        }

        // En compras el número lo pone el proveedor, así que no hay unicidad garantizada ni
        // restricción en la tabla: se guarda tal cual, que es el dato con el que se lo reclama.
        return sprintf('%04d-%08d', (int) $c['punto_venta'], (int) $c['nro_factura']);
    }

    private function proveedor(string $nombre, bool $dryRun): ?int
    {
        if ($nombre === '') {
            return null;
        }
        if (array_key_exists($nombre, $this->proveedores)) {
            return $this->proveedores[$nombre];
        }

        $this->stats['proveedores_creados']++;

        return $this->proveedores[$nombre] = $dryRun ? null : Proveedor::create(['nombre' => $nombre])->id;
    }

    private function categoria(?string $nombre): ?int
    {
        if ($nombre === null || $nombre === '') {
            return null;
        }

        return $this->categorias[$nombre] ??= Categoria::create(['tipo' => 'compra', 'nombre' => $nombre])->id;
    }

    private function producto(array $item, bool $dryRun): ?int
    {
        $legacy = $item['producto_legacy_id'];
        if ($legacy === null) {
            $this->stats['renglones_libres']++;

            return null;
        }

        $clave = (string) $legacy;
        if (array_key_exists($clave, $this->productos)) {
            return $this->productos[$clave];
        }

        $existente = Producto::whereKey($legacy)->value('id');
        if ($existente !== null) {
            return $this->productos[$clave] = $existente;
        }

        $this->stats['productos_legacy_creados']++;
        if ($dryRun) {
            return $this->productos[$clave] = null;
        }

        return $this->productos[$clave] = Producto::create([
            'legacy_id' => $clave,
            'nombre' => $item['descripcion'],
            'codigo' => $item['codigo'],
            'tipo' => 'producto',
            'tipo_producto_id' => $this->tipoProducto($item['rubro']),
            'costo' => $item['precio_unitario'],
            'mostrar_en_ventas' => false,
            'mostrar_en_compras' => false,
            'activo' => false,
        ])->id;
    }

    private function tipoProducto(?string $rubro): ?int
    {
        if ($rubro === null || $rubro === '') {
            return null;
        }
        $this->tiposProducto = $this->tiposProducto ?: TipoProducto::pluck('id', 'nombre')->all();

        return $this->tiposProducto[$rubro] ??= TipoProducto::create(['nombre' => $rubro])->id;
    }

}
