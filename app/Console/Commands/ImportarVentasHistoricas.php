<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\TipoProducto;
use App\Models\Vendedor;
use App\Models\Venta;
use App\Models\VentaConcepto;
use App\Models\VentaItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Importa las Ventas históricas de Contagram desde los Excel de public/imports/ventas
 * (uno por año, 2021–2025). Ver docs/importacion_ventas_historicas.md para el detalle
 * completo de las reglas y gotchas de los archivos de origen.
 *
 * Idempotente: cada venta importada queda con `legacy_id` = "{año}-{Id del Excel}"
 * (el Id del Excel se repite entre años, no es único global). Si ya existe, se saltea.
 *
 * Alcance: solo comprobantes de venta (FC/FCA/FCB/FCC). Las notas de crédito/débito
 * históricas (NC/NCA/NCB/ND/NDA/NDB) quedan fuera de esta importación (decisión
 * 03/08/2026: el Excel no trae qué venta original corrigen, y `notas_credito_debito
 * .venta_id` es NOT NULL) — se cuentan y loguean, no se pierden silenciosamente.
 */
class ImportarVentasHistoricas extends Command
{
    protected $signature = 'ventas:importar-historicas
        {--dry-run : No escribe nada, solo reporta estadisticas y filas problematicas}
        {--anio= : Procesar un solo anio (ej. 2021) en vez de los 5}';

    protected $description = 'Importa las Ventas históricas de los Excel de public/imports/ventas (idempotente)';

    private const COMPROBANTES_VENTA = ['FC', 'FCA', 'FCB', 'FCC'];
    private const COMPROBANTES_NC_ND = ['NC', 'NCA', 'NCB', 'ND', 'NDA', 'NDB'];

    private array $cacheClientes = [];
    private array $cacheProductos = [];
    private array $cacheProveedores = [];
    private array $cacheTiposProducto = [];
    private array $cacheCategorias = [];
    private array $cacheListas = [];
    private array $cacheVendedores = [];

    private array $stats = [
        'ventas_creadas' => 0,
        'ventas_ya_existian' => 0,
        'items_creados' => 0,
        'nc_nd_salteadas' => 0,
        'filas_sin_tipo_comprobante_reconocido' => 0,
        'clientes_creados' => 0,
        'productos_creados' => 0,
        'productos_sin_match_ejemplos' => [],
        'categoria_sin_match_ejemplos' => [],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $soloAnio = $this->option('anio');
        $anios = $soloAnio ? [$soloAnio] : ['2021', '2022', '2023', '2024', '2025'];

        $this->precargarCatalogos();

        $headerCanonico = null;

        foreach ($anios as $anio) {
            $path = public_path("imports/ventas/Ventas {$anio}.xlsx");
            if (! file_exists($path)) {
                $this->warn("No existe: {$path}");
                continue;
            }

            $filas = Excel::toArray([], $path)[0];

            // 2023 no tiene fila de headers al principio: los datos arrancan en la fila 0
            // y la fila de headers real quedó pegada al final (ver docs). Para ese año
            // usamos el header canónico de otro archivo y descartamos la fila de header
            // duplicada donde aparezca (columna 0 == 'Id' literal).
            if ($headerCanonico === null) {
                $headerCanonico = $this->deduplicarHeader($filas[0]);
            }

            if ($this->esHeaderLiteral($filas[0])) {
                $header = array_shift($filas);
            } else {
                $header = $headerCanonico;
            }
            $header = $this->deduplicarHeader($header);

            $filas = array_values(array_filter($filas, fn ($f) => ! $this->esHeaderLiteral($f)));

            $this->info("Año {$anio}: ".count($filas).' filas de datos');

            $filasPorId = [];
            foreach ($filas as $fila) {
                $row = array_combine($header, $fila);
                $filasPorId[(string) $row['Id']][] = $row;
            }

            foreach ($filasPorId as $idExcel => $rows) {
                $this->procesarComprobante($anio, $idExcel, $rows, $dryRun);
            }
        }

        $this->reportar();

        return self::SUCCESS;
    }

    /**
     * El Excel trae la columna "Tipo" repetida (A/B del comprobante, y el rubro del
     * item) con el mismo nombre literal — array_combine se quedaría solo con la
     * última. Igual que pandas al leer duplicados, la segunda ocurrencia pasa a
     * llamarse "Tipo.1".
     */
    private function deduplicarHeader(array $header): array
    {
        $vistos = [];
        foreach ($header as $i => $nombre) {
            $nombre = (string) $nombre;
            if (isset($vistos[$nombre])) {
                $vistos[$nombre]++;
                $header[$i] = $nombre.'.'.$vistos[$nombre];
            } else {
                $vistos[$nombre] = 0;
            }
        }

        return $header;
    }

    private function esHeaderLiteral(array $fila): bool
    {
        return isset($fila[0]) && trim((string) $fila[0]) === 'Id';
    }

    private function precargarCatalogos(): void
    {
        foreach (Cliente::pluck('id', 'nombre') as $nombre => $id) {
            $this->cacheClientes[$nombre] = $id;
        }
        foreach (Proveedor::pluck('id', 'nombre') as $nombre => $id) {
            $this->cacheProveedores[$nombre] = $id;
        }
        foreach (TipoProducto::pluck('id', 'nombre') as $nombre => $id) {
            $this->cacheTiposProducto[$nombre] = $id;
        }
        foreach (Categoria::where('tipo', 'venta')->pluck('id', 'nombre') as $nombre => $id) {
            $this->cacheCategorias[$nombre] = $id;
        }
        foreach (ListaPrecio::pluck('id', 'nombre') as $nombre => $id) {
            $this->cacheListas[$this->normalizarEspacios($nombre)] = $id;
        }
        foreach (Vendedor::pluck('id', 'nombre') as $nombre => $id) {
            $this->cacheVendedores[$nombre] = $id;
        }
        foreach (Producto::whereNotNull('codigo')->pluck('id', 'codigo') as $codigo => $id) {
            $this->cacheProductos[$this->normalizarEspacios($codigo)] = $id;
        }
    }

    /**
     * Excel::toArray() sin formateo devuelve las celdas de fecha como el serial
     * numérico de Excel (ej. 44365.0 = 2021-06-18), no como string/Carbon. Sin esta
     * conversión, Eloquent intenta parsear el float como fecha y arma cualquier cosa
     * (se vio "1970-01-01 12:19:29" al probar en el VPS: interpretó el serial como
     * segundos desde epoch).
     */
    private function fechaExcel($valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_numeric($valor)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $valor)->format('Y-m-d');
        }

        return (string) $valor;
    }

    private function normalizarEspacios(?string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }

    private function procesarComprobante(string $anio, string $idExcel, array $rows, bool $dryRun): void
    {
        $legacyId = "{$anio}-{$idExcel}";

        $primera = $rows[0];
        $tipoComp = trim((string) ($primera['Tipo de Comprobante'] ?? ''));

        if (in_array($tipoComp, self::COMPROBANTES_NC_ND, true)) {
            $this->stats['nc_nd_salteadas']++;

            return;
        }

        if (! in_array($tipoComp, self::COMPROBANTES_VENTA, true)) {
            $this->stats['filas_sin_tipo_comprobante_reconocido']++;

            return;
        }

        if (Venta::withTrashed()->where('legacy_id', $legacyId)->exists()) {
            $this->stats['ventas_ya_existian']++;

            return;
        }

        $tipoAB = $this->resolverTipoAB($tipoComp, $primera);
        if ($tipoAB === null) {
            $this->stats['filas_sin_tipo_comprobante_reconocido']++;

            return;
        }

        DB::transaction(function () use ($anio, $legacyId, $rows, $primera, $tipoAB, $dryRun) {
            $clienteId = $this->resolverCliente($primera['Cliente'] ?? null, $dryRun);
            $categoriaId = $this->resolverCategoria($primera['Categoría'] ?? $primera['Categor�a'] ?? null);
            $listaPrecioId = $this->resolverListaPrecio($primera['Lista de Precios'] ?? null);
            $vendedorId = $this->resolverVendedor($primera['Vendedor'] ?? null, $dryRun);

            // nro_comprobante queda null en el historial: el "Punto de Venta-N° Factura"
            // del Excel no es único entre años (ventas.tipo_comprobante+nro_comprobante
            // sí lo es en el esquema) y el propio modelo documenta este campo como "dato
            // sin emisión fiscal" — el legacy_id ya permite rastrear el comprobante
            // original si hace falta.
            $venta = new Venta([
                'origen' => 'manual',
                'cliente_id' => $clienteId,
                'categoria_id' => $categoriaId,
                'lista_precio_id' => $listaPrecioId,
                'fecha_emision' => $this->fechaExcel($primera['Emisión'] ?? $primera['Emisi�n'] ?? null),
                'fecha_vto_cobro' => $this->fechaExcel($primera['Vencimiento'] ?? null),
                'tipo_comprobante' => $tipoAB,
                'nro_comprobante' => null,
                'subtotal_sin_descuento' => (float) ($primera['Subtotal sin Descuento'] ?? 0),
                'descuento' => (float) ($primera['Descuento en $'] ?? 0),
                'subtotal_con_descuento' => (float) ($primera['Subtotal con Descuento'] ?? 0),
                'total' => (float) ($primera['Total Venta'] ?? 0),
                'nota_cliente' => $primera['Nota para el Cliente'] ?: null,
                'nota_interna' => $primera['Nota Interna'] ?: null,
                'vendedor_id' => $vendedorId,
                'legacy_id' => $legacyId,
            ]);

            if ($dryRun) {
                $this->stats['ventas_creadas']++;
                foreach ($rows as $row) {
                    $this->resolverProducto($row, $dryRun);
                    $this->stats['items_creados']++;
                }

                return;
            }

            $venta->save();
            $this->stats['ventas_creadas']++;

            foreach ($rows as $row) {
                $productoId = $this->resolverProducto($row, $dryRun);
                $cantidad = (float) ($row['Cantidad'] ?? 0);
                $precioUnitario = (float) ($row['Precio Unitario'] ?? 0);

                VentaItem::create([
                    'venta_id' => $venta->id,
                    'producto_id' => $productoId,
                    'descripcion' => (string) ($row['Producto/Servicio'] ?? ''),
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precioUnitario,
                    'iva_pct' => $this->resolverIvaPct($row),
                    'subtotal' => round($cantidad * $precioUnitario, 2),
                    'subtotal_con_iva' => (float) ($row['Precio de Venta'] ?? 0),
                ]);
                $this->stats['items_creados']++;
            }

            $percIva = (float) ($primera['Perc. IVA'] ?? 0);
            $percIibb = (float) ($primera['Perc. IIBB'] ?? 0);
            $impInternos = (float) ($primera['Imp. Internos'] ?? 0);

            if ($percIva != 0) {
                VentaConcepto::create(['venta_id' => $venta->id, 'tipo' => 'percepcion', 'concepto' => 'Perc. IVA', 'monto' => $percIva]);
            }
            if ($percIibb != 0) {
                VentaConcepto::create(['venta_id' => $venta->id, 'tipo' => 'percepcion', 'concepto' => 'Perc. IIBB', 'monto' => $percIibb]);
            }
            if ($impInternos != 0) {
                VentaConcepto::create(['venta_id' => $venta->id, 'tipo' => 'impuesto_interno', 'concepto' => 'Impuestos Internos', 'monto' => $impInternos]);
            }
        });
    }

    private function resolverTipoAB(string $tipoComp, array $fila): ?string
    {
        return match ($tipoComp) {
            'FCA' => 'A',
            'FCB' => 'B',
            'FCC' => 'C',
            // "FC" sin sufijo A/B: si la columna Tipo viene vacía (dato faltante en el
            // Excel, ~45% de los casos), se asume 'B' (consumidor final — decisión
            // 03/08/2026, ver docs/importacion_ventas_historicas.md).
            'FC' => in_array(trim((string) ($fila['Tipo'] ?? '')), ['A', 'B', 'C', 'E'], true)
                ? trim((string) $fila['Tipo'])
                : 'B',
            default => null,
        };
    }

    private function resolverIvaPct(array $row): ?string
    {
        $mapa = ['IVA - 2,5%' => '2.5', 'IVA - 5%' => '5', 'IVA - 10,5%' => '10.5', 'IVA - 21%' => '21', 'IVA - 27%' => '27'];
        foreach ($mapa as $col => $pct) {
            if ((float) ($row[$col] ?? 0) != 0) {
                return $pct;
            }
        }
        if ((float) ($row['Exento'] ?? 0) != 0) {
            return 'exento';
        }
        if ((float) ($row['No Gravado'] ?? 0) != 0) {
            return 'no_gravado';
        }

        return null;
    }

    private function resolverCliente(?string $nombreRaw, bool $dryRun): ?int
    {
        $nombre = trim((string) $nombreRaw);
        if ($nombre === '') {
            return null;
        }
        if (isset($this->cacheClientes[$nombre])) {
            return $this->cacheClientes[$nombre];
        }
        if ($dryRun) {
            $this->stats['clientes_creados']++;

            return null;
        }
        $cliente = Cliente::create(['nombre' => $nombre]);
        $this->cacheClientes[$nombre] = $cliente->id;
        $this->stats['clientes_creados']++;

        return $cliente->id;
    }

    private function resolverCategoria(?string $valor): ?int
    {
        $nombre = trim((string) $valor);
        if (isset($this->cacheCategorias[$nombre])) {
            return $this->cacheCategorias[$nombre];
        }
        if ($nombre !== '') {
            $this->stats['categoria_sin_match_ejemplos'][$nombre] = true;
        }

        return null;
    }

    private function resolverListaPrecio(?string $valor): ?int
    {
        $nombre = $this->normalizarEspacios((string) $valor);
        if ($nombre === '') {
            return null;
        }

        return $this->cacheListas[$nombre] ?? null;
    }

    private function resolverVendedor(?string $nombreRaw, bool $dryRun): ?int
    {
        $nombre = trim((string) $nombreRaw);
        if ($nombre === '') {
            return null;
        }
        if (isset($this->cacheVendedores[$nombre])) {
            return $this->cacheVendedores[$nombre];
        }
        if ($dryRun) {
            return null;
        }
        $vendedor = Vendedor::create(['nombre' => $nombre]);
        $this->cacheVendedores[$nombre] = $vendedor->id;

        return $vendedor->id;
    }

    private function resolverProducto(array $row, bool $dryRun): ?int
    {
        $codigoRaw = $row['Código'] ?? $row['C�digo'] ?? null;
        $codigo = $this->normalizarEspacios((string) $codigoRaw);

        if ($codigo !== '' && isset($this->cacheProductos[$codigo])) {
            return $this->cacheProductos[$codigo];
        }

        // Fallback: el primer token numérico del código suele ser el id interno
        // del producto viejo (ej. "12690 12690" -> 12690) para servicios sin
        // codigo propio en la tabla productos.
        if ($codigo !== '' && preg_match('/^(\d+)/', $codigo, $m)) {
            $producto = Producto::find((int) $m[1]);
            if ($producto) {
                return $producto->id;
            }
        }

        if (count($this->stats['productos_sin_match_ejemplos']) < 30) {
            $this->stats['productos_sin_match_ejemplos'][] = $codigo !== '' ? $codigo : '(sin código: '.($row['Producto/Servicio'] ?? '').')';
        }

        if ($dryRun) {
            $this->stats['productos_creados']++;

            return null;
        }

        $tipoProductoNombre = trim((string) ($row['Tipo.1'] ?? $row['Tipo'] ?? ''));
        $tipoProductoId = $this->cacheTiposProducto[$tipoProductoNombre] ?? null;
        $proveedorNombre = trim((string) ($row['Proveedor'] ?? ''));
        $proveedorId = $this->cacheProveedores[$proveedorNombre] ?? null;
        $esServicio = str_contains(mb_strtolower($tipoProductoNombre), 'mano de obra');

        $producto = Producto::create([
            'nombre' => (string) ($row['Producto/Servicio'] ?? 'Producto sin nombre'),
            'codigo' => null,
            'tipo' => $esServicio ? 'servicio' : 'producto',
            'tipo_producto_id' => $tipoProductoId,
            'proveedor_id' => $proveedorId,
            'precio_venta' => (float) ($row['Precio de Venta'] ?? 0),
            'costo' => (float) ($row['Costo Total Actual'] ?? 0),
        ]);
        $this->stats['productos_creados']++;

        return $producto->id;
    }

    private function reportar(): void
    {
        $this->newLine();
        $this->info('=== Resultado ===');
        $this->line('Ventas creadas: '.$this->stats['ventas_creadas']);
        $this->line('Ventas ya existentes (saltadas): '.$this->stats['ventas_ya_existian']);
        $this->line('Items creados: '.$this->stats['items_creados']);
        $this->line('NC/ND salteadas (fuera de alcance): '.$this->stats['nc_nd_salteadas']);
        $this->line('Comprobantes sin tipo reconocido (salteados): '.$this->stats['filas_sin_tipo_comprobante_reconocido']);
        $this->line('Clientes creados: '.$this->stats['clientes_creados']);
        $this->line('Productos creados (sin match por código): '.$this->stats['productos_creados']);
        if (! empty($this->stats['categoria_sin_match_ejemplos'])) {
            $this->warn('Categorías sin match: '.implode(', ', array_slice(array_keys($this->stats['categoria_sin_match_ejemplos']), 0, 15)));
        }
        if (! empty($this->stats['productos_sin_match_ejemplos'])) {
            $this->warn('Ejemplos de código sin match: '.implode(' | ', array_slice($this->stats['productos_sin_match_ejemplos'], 0, 15)));
        }
    }
}
