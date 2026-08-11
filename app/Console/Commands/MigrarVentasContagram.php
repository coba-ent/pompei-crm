<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\ListaPrecio;
use App\Models\NotaCreditoDebito;
use App\Models\Producto;
use App\Models\TipoProducto;
use App\Models\Vendedor;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Migracion\ComprobantesContagram;
use App\Services\Migracion\LectorExcelContagram;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migra el histórico completo de ventas de Contagram (2021 → 05/08/2026) al CRM.
 *
 * Reemplaza a `ventas:importar-historicas`, que quedó con reglas que después se demostraron
 * equivocadas (ver docs/importacion_2021_2026_plan_tecnico.md §3.9). Las diferencias de fondo:
 * el total de cabecera sale del `c/ cobro` y no del por-ítem, los comprobantes se agrupan por
 * `Id`+familia y no por `Id` solo, y las NC/ND se importan en vez de saltearse.
 *
 * **No mueve stock, a propósito.** Estas ventas son historia: la mercadería ya salió y el saldo
 * actual de cada producto lo refleja. Descontarla de nuevo dejaría el stock en negativo por seis
 * años de ventas. `VentaObserver` tiene el guardarraíl del otro lado (no reintegra al borrar una
 * venta con `legacy_id`), cubierto por `tests/Feature/VentaMigradaStockTest.php`.
 *
 * Idempotente vía `legacy_id`: se puede correr las veces que haga falta.
 */
class MigrarVentasContagram extends Command
{
    protected $signature = 'migracion:ventas
        {--dry-run : No escribe nada; sólo reporta las cifras y los problemas}
        {--anio= : Procesar un solo año}
        {--excluir= : JSON con los legacy_id a excluir (duplicados de Mercado Libre)}
        {--force : Corre aunque existan ventas del import viejo (LEER el aviso antes)}';

    protected $description = 'Migra las ventas históricas de Contagram 2021-2026 (idempotente, no mueve stock)';

    /**
     * Borradas en Contagram, confirmado por el usuario buscándolas en el sistema viejo.
     *
     * La 24267 figura en el `c/ cobro` sin ítems y es la única de los 6 años en esa condición, así
     * que además está confirmada por evidencia propia del archivo.
     *
     * La 2140 resultó ser una sola en los 6 años (`2021-FC-2140`, $40.034,40, Maria 1149368745,
     * 20/12/2021) y el usuario confirmó el 10/08/2026 que tampoco aparece en Contagram. Figura
     * **cobrada al 100%** en el export, así que fue borrada después de exportarla: anotada en
     * docs/importacion_casos_a_revisar.md por si esos $40.034 entraron a la caja de verdad.
     */
    private const BORRADAS = ['2026-FC-24267', '2021-FC-2140'];

    private array $stats = [];

    /** @var array<string,int|null> cachés nombre => id, para no consultar 24.000 veces */
    private array $clientes = [];
    private array $categorias = [];
    private array $listas = [];
    private array $vendedores = [];
    private array $depositos = [];
    private array $productos = [];

    private array $problemas = [];

    /** legacy_id => timestamp de creación reconstruido (ver calcularTimestamps). */
    private array $timestamps = [];

    public function handle(LectorExcelContagram $lector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $base = public_path('imports');
        $servicio = new ComprobantesContagram($lector, $base);

        $this->stats = array_fill_keys([
            'ventas_creadas', 'ventas_ya_existian', 'items_creados', 'nc_creadas', 'nd_creadas',
            'fuera_de_corte', 'excluidas_ml', 'excluidas_borradas', 'clientes_creados',
            'productos_legacy_creados', 'renglones_libres', 'nro_comprobante_en_conflicto',
        ], 0);

        if (! $this->verificarImportViejo()) {
            return self::FAILURE;
        }

        $excluidos = $this->cargarExclusiones();
        $anios = $this->option('anio') ? [$this->option('anio')] : ComprobantesContagram::ANIOS;

        $this->info($dryRun ? '— DRY RUN: no se escribe nada —' : '— IMPORTANDO EN SERIO —');
        $this->precargarCaches();

        // El header canónico se toma de 2022 porque `Ventas 2023.xlsx` no trae encabezado (§3.3).
        // Verificado: los 6 años tienen exactamente el mismo header (§3.8).
        $headerCanonico = $lector->leer($base.'/Ventas/Ventas 2022.xlsx')['header'];

        $totales = ['FC' => 0.0, 'NC' => 0.0, 'ND' => 0.0, 'cobrado' => 0.0];

        foreach ($anios as $anio) {
            $this->line("Leyendo {$anio}…");
            $comprobantes = $servicio->delAnio($anio, $anio === '2023' ? $headerCanonico : null);
            $this->timestamps = $this->calcularTimestamps($comprobantes);

            $barra = $this->output->createProgressBar(count($comprobantes));
            foreach ($comprobantes as $legacyId => $c) {
                $barra->advance();

                if (! $servicio->dentroDelCorte($c['fecha_emision'])) {
                    $this->stats['fuera_de_corte']++;

                    continue;
                }
                if (in_array($legacyId, self::BORRADAS, true)) {
                    $this->stats['excluidas_borradas']++;

                    continue;
                }
                if (isset($excluidos[$legacyId])) {
                    $this->stats['excluidas_ml']++;

                    continue;
                }

                $totales[$c['familia']] += $c['total'];
                if ($c['familia'] === 'FC') {
                    $totales['cobrado'] += $c['cobrado'];
                }

                $c['familia'] === 'FC'
                    ? $this->importarVenta($c, $dryRun)
                    : $this->importarNota($c, $dryRun);
            }
            $barra->finish();
            $this->newLine();
        }

        $this->reportar($totales, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Ventas de Mercado Libre que ya se convirtieron a mano en el CRM: importarlas sería duplicar
     * plata. La lista se recalcula contra la base antes de cada corrida (§7) — no se hardcodea,
     * justamente porque siguieron apareciendo órdenes viejas convertidas tarde.
     */
    private function cargarExclusiones(): array
    {
        $path = $this->option('excluir');
        if (! $path) {
            $this->warn('Sin --excluir: NO se filtran los duplicados de Mercado Libre. Ver plan §4.2/§7.');

            return [];
        }

        $lista = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->info('Exclusiones de Mercado Libre cargadas: '.count($lista));

        return array_flip($lista);
    }

    /**
     * Aborta si en la base quedaron ventas del import viejo (`ventas:importar-historicas`).
     *
     * Aquél usaba `legacy_id` = `{año}-{Id}` y éste usa `{año}-{familia}-{Id}`, así que la
     * comprobación de idempotencia **no las reconoce** y volvería a importar las mismas ventas:
     * $1.500 millones duplicados sin un solo error visible. Hay que borrarlas antes.
     */
    private function verificarImportViejo(): bool
    {
        $viejas = Venta::withTrashed()
            ->whereNotNull('legacy_id')
            ->where('legacy_id', 'not like', '%-FC-%')
            ->where('legacy_id', 'not like', '%-NC-%')
            ->where('legacy_id', 'not like', '%-ND-%')
            ->count();

        if ($viejas === 0) {
            return true;
        }

        $this->error("Hay {$viejas} ventas del import viejo (legacy_id tipo '2021-123').");
        $this->line('Su legacy_id tiene otro formato, así que NO se detectarían como ya importadas');
        $this->line('y quedarían duplicadas. Borralas antes de correr esto:');
        $this->line("  Venta::withTrashed()->whereNotNull('legacy_id')");
        $this->line("      ->where('legacy_id','not like','%-FC-%')->forceDelete();");

        if ($this->option('force')) {
            $this->warn('--force: sigo igual.');

            return true;
        }

        return false;
    }

    private function precargarCaches(): void
    {
        foreach (Cliente::pluck('id', 'nombre') as $nombre => $id) {
            // Espacios colapsados: 377 clientes tienen doble espacio y los exports traen uno (§3.6).
            $this->clientes[preg_replace('/\s+/u', ' ', trim($nombre))] = $id;
        }
        $this->categorias = Categoria::where('tipo', 'venta')->pluck('id', 'nombre')->all();
        $this->listas = ListaPrecio::pluck('id', 'nombre')->all();
        $this->vendedores = Vendedor::pluck('id', 'nombre')->all();
        $this->depositos = Deposito::pluck('id', 'nombre')->all();
        $this->productos = Producto::whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
    }

    private function importarVenta(array $c, bool $dryRun): void
    {
        if (Venta::withTrashed()->where('legacy_id', $c['legacy_id'])->exists()) {
            $this->stats['ventas_ya_existian']++;

            return;
        }

        DB::transaction(function () use ($c, $dryRun) {
            $datos = [
                'origen' => 'manual',
                'legacy_id' => $c['legacy_id'],
                'cliente_id' => $this->cliente($c['cliente'], $dryRun),
                'categoria_id' => $this->catalogo(Categoria::class, $this->categorias, $c['categoria'], $dryRun, ['tipo' => 'venta']),
                'lista_precio_id' => $this->catalogo(ListaPrecio::class, $this->listas, $c['lista_precio'], $dryRun),
                'vendedor_id' => $this->catalogo(Vendedor::class, $this->vendedores, $c['vendedor'], $dryRun),
                'deposito_id' => $this->catalogo(Deposito::class, $this->depositos, $c['deposito'], $dryRun),
                'fecha_emision' => $c['fecha_emision'],
                'fecha_vto_cobro' => $c['fecha_vto_cobro'],
                'servicio_desde' => $c['servicio_desde'],
                'servicio_hasta' => $c['servicio_hasta'],
                'tipo_comprobante' => $this->tipoComprobante($c),
                'nro_comprobante' => $this->nroComprobante($c, $dryRun),
                'subtotal_sin_descuento' => $c['subtotal_sin_descuento'],
                'descuento' => $c['descuento'],
                'subtotal_con_descuento' => $c['subtotal_con_descuento'],
                'total' => $c['total'],
                'nota_cliente' => $c['nota_cliente'],
                'nota_interna' => $c['nota_interna'],
            ];

            $this->stats['ventas_creadas']++;
            $this->stats['items_creados'] += count($c['items']);

            if ($dryRun) {
                foreach ($c['items'] as $item) {
                    $this->producto($item, $dryRun);
                }

                return;
            }

            // Eloquent respeta created_at si ya viene seteado, así que se asigna antes de save().
            $ts = $this->timestamps[$c['legacy_id']] ?? $c['fecha_emision'];
            $venta = new Venta($datos);
            $venta->created_at = $ts;
            $venta->updated_at = $ts;
            $venta->save();

            foreach ($c['items'] as $item) {
                $vi = new VentaItem([
                    'venta_id' => $venta->id,
                    'producto_id' => $this->producto($item, $dryRun),
                    'descripcion' => $item['descripcion'],
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'iva_pct' => $item['iva_pct'],
                    'subtotal' => $item['subtotal'],
                    'subtotal_con_iva' => $item['subtotal_con_iva'],
                ]);
                $vi->created_at = $ts;
                $vi->updated_at = $ts;
                $vi->save();
            }
        });
    }

    /**
     * NC/ND sin `venta_id`: el export no dice qué venta corrigen y la columna es nullable. Se
     * importan igual porque son plata que pasó por la caja — perderlas descuadraría el total.
     * Tampoco se les cargan ítems: `nota_credito_debito_items.producto_id` es NOT NULL y el 6% de
     * los renglones no tiene producto identificable.
     */
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
            'tipo' => $c['familia'] === 'NC' ? 'credito' : 'debito',
            'afecta_stock' => false,
            'fecha_emision' => $c['fecha_emision'],
            // Obligatorio en el esquema. El export no lo trae: se imputa al mes de emisión, que es
            // el comportamiento por defecto del CRM cuando se carga una NC/ND a mano.
            'mes_imputacion' => $c['fecha_emision']?->startOfMonth(),
            // El signo vive en `tipo`: el Excel trae las NC en negativo y guardarlas así haría que
            // `totalNotasCredito()` las reste dos veces (Venta::aCobrar ya las descuenta).
            'monto' => abs($c['total']),
            'tipo_comprobante' => $c['tipo'] ?? 'B',
            'descripcion' => trim(($c['nota_cliente'] ?? '').' '.($c['nota_interna'] ?? '')) ?: null,
        ]);
        $nota->created_at = $ts;
        $nota->updated_at = $ts;
        $nota->save();
    }

    /**
     * `created_at` de cada comprobante = **su fecha de emisión**, no el momento del import.
     *
     * La tabla de Ventas ordena por fecha de creación (`order: [[3,'desc']]` en ventas.js, decisión
     * deliberada). Con el `created_at` del import, las 23.500 ventas históricas se van al tope y
     * tapan las del día. Corregir el dato en vez del orden es además lo semánticamente cierto: esa
     * venta se creó en 2021. No se pierde trazabilidad — `legacy_id` ya distingue las migradas.
     *
     * `Emisión` no trae hora, así que todas las de un día caerían a las 00:00:00 y quedarían
     * desordenadas entre sí. Se reparten entre las 09:00 y las 18:00 **según el `Id` de Contagram**,
     * que es correlativo por orden de carga: así el orden dentro del día es el real.
     *
     * @return array<string, CarbonImmutable>
     */
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

    /** `Tipo` vacío → B (consumidor final): son las ventas sin comprobante fiscal (§3.11). */
    private function tipoComprobante(array $c): string
    {
        return in_array($c['tipo'], ['A', 'B', 'C', 'E'], true) ? $c['tipo'] : 'B';
    }

    /**
     * Número fiscal real del sistema viejo (`0005-00002304`), sólo para las aprobadas por ARCA.
     *
     * Las `Sin Enviar`/`Error` reusan números entre sí, así que van con null. No colisiona con la
     * serie del CRM: ésta emite sobre el punto de venta 9 y la serie interna usa el prefijo `0001-`.
     * Si aun así el número ya estuviera tomado, se deja null antes que abortar la venta entera —
     * el `legacy_id` alcanza para rastrearla hasta Contagram.
     */
    private function nroComprobante(array $c, bool $dryRun): ?string
    {
        if ($c['arca'] !== 'Aprobado' || ! $c['punto_venta'] || ! $c['nro_factura']) {
            return null;
        }

        $nro = sprintf('%04d-%08d', (int) $c['punto_venta'], (int) $c['nro_factura']);
        $tipo = $this->tipoComprobante($c);

        if (Venta::withTrashed()->where('tipo_comprobante', $tipo)->where('nro_comprobante', $nro)->exists()) {
            $this->stats['nro_comprobante_en_conflicto']++;
            $this->problemas[] = "{$c['legacy_id']}: nro {$tipo} {$nro} ya existe, se guarda sin número";

            return null;
        }

        return $nro;
    }

    private function cliente(string $nombre, bool $dryRun): ?int
    {
        if ($nombre === '') {
            return null;
        }
        // array_key_exists y no isset: en dry-run se cachea null a propósito (ver abajo), e isset
        // lo trataría como ausente.
        if (array_key_exists($nombre, $this->clientes)) {
            return $this->clientes[$nombre];
        }

        $this->stats['clientes_creados']++;
        if ($dryRun) {
            // Cachear el null igual que se cachearía el id: si no, cada venta del mismo cliente lo
            // vuelve a contar y el dry-run informa 18.652 clientes nuevos donde hay ~15.000.
            return $this->clientes[$nombre] = null;
        }

        return $this->clientes[$nombre] = Cliente::create(['nombre' => $nombre])->id;
    }

    /** Busca por nombre en la caché y crea si falta. Los catálogos del histórico son chicos. */
    private function catalogo(string $modelo, array &$cache, ?string $nombre, bool $dryRun, array $extra = []): ?int
    {
        if ($nombre === null || $nombre === '') {
            return null;
        }
        if (isset($cache[$nombre])) {
            return $cache[$nombre];
        }
        if ($dryRun) {
            return null;
        }

        return $cache[$nombre] = $modelo::create($extra + ['nombre' => $nombre])->id;
    }

    /**
     * Producto del renglón. Tres caminos, en orden (§4.5):
     * 1. ya migrado antes (`legacy_id`);
     * 2. el Id del código coincide con un producto existente — los ids se preservaron en el import
     *    de productos, así que el Id de Contagram es el `id` del CRM;
     * 3. no existe: se crea **inactivo**, con los datos del propio renglón.
     *
     * Sin Id en el código es un renglón libre (262 casos: `CODIGO LIBRE`, `Ajuste cta cte`…), que
     * no es un producto y se guarda sólo como descripción.
     */
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
            return $this->productos[$clave] = null;   // idem cliente(): sin esto se cuenta por renglón
        }

        return $this->productos[$clave] = Producto::create([
            'legacy_id' => $clave,
            'nombre' => $item['descripcion'],
            'codigo' => $item['codigo'],
            'tipo' => 'producto',
            'tipo_producto_id' => $this->tipoProducto($item['rubro']),
            'costo' => $item['costo'],
            'precio_venta' => $item['precio_unitario'],
            'mostrar_en_ventas' => false,
            'mostrar_en_compras' => false,
            // Inactivo: existe para que la venta histórica tenga a qué apuntar, no para venderse.
            'activo' => false,
        ])->id;
    }

    private array $tiposProducto = [];

    private function tipoProducto(?string $rubro): ?int
    {
        if ($rubro === null || $rubro === '') {
            return null;
        }
        $this->tiposProducto = $this->tiposProducto ?: TipoProducto::pluck('id', 'nombre')->all();

        return $this->tiposProducto[$rubro] ??= TipoProducto::create(['nombre' => $rubro])->id;
    }

    private function reportar(array $totales, bool $dryRun): void
    {
        $this->newLine();
        $this->table(['Concepto', 'Cantidad'], collect($this->stats)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), number_format($v)])->values()->all());

        $this->newLine();
        $this->table(['Importe', 'Migrado', 'Control (plan §6)'], [
            ['Facturado', number_format($totales['FC'], 2), '1.570.665.960,38'],
            ['Cobrado (dato del Excel)', number_format($totales['cobrado'], 2), '1.506.014.720,12'],
            ['Notas de crédito', number_format(abs($totales['NC']), 2), '56.207.437,48'],
            ['Notas de débito', number_format($totales['ND'], 2), '2.203.385,37'],
        ]);
        $this->comment('Las cifras de control son SIN corte ni exclusiones; restar lo excluido antes de comparar.');

        if ($this->problemas !== []) {
            $this->newLine();
            $this->warn('Problemas ('.count($this->problemas).'):');
            foreach (array_slice($this->problemas, 0, 20) as $p) {
                $this->line('  '.$p);
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY RUN: no se escribió nada. Los cobros van en un comando aparte.');
        }
    }
}
