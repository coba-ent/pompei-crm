<?php

namespace App\Services\Import;

use App\Http\Requests\Import\ReglasClienteImportacion;
use App\Http\Requests\Import\ReglasProductoImportacion;
use App\Http\Requests\Import\ReglasProveedorImportacion;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Aplica el mapeo de columnas elegido en el paso 2, fila por fila: valida
 * cada fila con las mismas reglas que el alta manual (reutilizando
 * `ReglasCliente`/`ReglasProveedor`/`ReglasProducto`), y crea el registro si
 * es válida. Cada fila es su propia transacción corta — una fila inválida no
 * aborta el resto del archivo (research.md §4, Principio IV).
 */
class ImportadorFilas
{
    public function __construct(private StockService $stockService) {}

    /**
     * @param  array<int|string, string>  $mapeo  índice de columna => campo destino ('' = no importar, 'personalizado' = campo personalizado)
     * @param  array<int|string, string>  $personalizados  índice de columna => nombre elegido para el campo personalizado
     * @return array{importados: int, fallidos: array<int, array{fila: int, motivo: string}>, advertencias: array<int, array{fila: int, motivo: string}>}
     */
    public function importar(string $entidad, string $rutaCompleta, array $mapeo, array $personalizados, ?User $usuario = null): array
    {
        // Procesamiento síncrono sin cola (Assumptions del spec): un archivo de varios
        // miles de filas puede superar el límite por defecto de PHP (max_execution_time).
        set_time_limit(0);

        $definicion = DefinicionCamposImportables::paraEntidad($entidad);
        $filas = (Excel::toArray(null, $rutaCompleta))[0] ?? [];
        $filasDatos = array_slice($filas, 1); // fila 0 = encabezados

        $catalogosFk = $this->precargarCatalogosFk($mapeo, $definicion);
        $reglas = $this->construirReglas($entidad, $definicion);

        $importados = 0;
        $fallidos = [];
        $advertencias = [];

        foreach ($filasDatos as $i => $celdas) {
            $numeroFila = $i + 2; // +1 por el encabezado, +1 por ser 1-based

            [$datos, $advertenciasFila] = $this->mapearFila($celdas, $mapeo, $personalizados, $definicion, $catalogosFk);

            [$valido, $motivo] = $this->validarFila($datos, $reglas);
            if (! $valido) {
                $fallidos[] = ['fila' => $numeroFila, 'motivo' => $motivo];

                continue;
            }

            // Un único INSERT por fila (`crear()` no encadena queries relacionadas) — ya es
            // atómico sin envolverlo en una transacción explícita; evita el costo de un
            // BEGIN/COMMIT extra por cada una de las miles de filas del archivo.
            $this->crear($entidad, $datos, $usuario);
            $importados++;

            foreach ($advertenciasFila as $motivo) {
                $advertencias[] = ['fila' => $numeroFila, 'motivo' => $motivo];
            }
        }

        return ['importados' => $importados, 'fallidos' => $fallidos, 'advertencias' => $advertencias];
    }

    /**
     * Catálogos de los campos FK-por-nombre mapeados, precargados una sola vez
     * (no por fila) — research.md §3.
     *
     * @param  array<int|string, string>  $mapeo
     * @param  array<string, array{fk?: array{modelo: class-string, scope?: string}}>  $definicion
     * @return array<int|string, \Illuminate\Support\Collection>
     */
    private function precargarCatalogosFk(array $mapeo, array $definicion): array
    {
        $catalogos = [];

        foreach ($mapeo as $indice => $campoDestino) {
            $def = $definicion[$campoDestino] ?? null;
            if (! $def || ! isset($def['fk'])) {
                continue;
            }

            $modelo = $def['fk']['modelo'];
            $query = $modelo::query();
            if (! empty($def['fk']['scope'])) {
                $query->{$def['fk']['scope']}();
            }

            $catalogos[$indice] = $query->get(['id', 'nombre']);
        }

        return $catalogos;
    }

    /**
     * @param  array<int, mixed>  $celdas
     * @param  array<int|string, string>  $mapeo
     * @param  array<int|string, string>  $personalizados
     * @param  array<string, array{etiqueta: string, obligatorio: bool, fk?: array{modelo: class-string, scope?: string}, default?: mixed}>  $definicion
     * @param  array<int|string, \Illuminate\Support\Collection>  $catalogosFk
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function mapearFila(array $celdas, array $mapeo, array $personalizados, array $definicion, array $catalogosFk): array
    {
        $datos = [];
        $advertencias = [];

        foreach ($mapeo as $indice => $campoDestino) {
            if ($campoDestino === '' || $campoDestino === null) {
                continue;
            }

            $valor = trim((string) ($celdas[$indice] ?? ''));
            if ($valor === '') {
                continue;
            }

            if ($campoDestino === 'personalizado') {
                $nombreCampo = trim((string) ($personalizados[$indice] ?? ''));
                if ($nombreCampo === '') {
                    continue;
                }
                $datos['campos_personalizados'][] = [
                    'nombre' => $nombreCampo,
                    'tipo' => 'texto',
                    'opciones' => null,
                    'valor' => $valor,
                ];

                continue;
            }

            $def = $definicion[$campoDestino] ?? null;
            if ($def === null) {
                continue;
            }

            if (isset($def['fk'])) {
                $normalizado = Str::of($valor)->lower()->ascii()->toString();
                $registro = ($catalogosFk[$indice] ?? collect())->first(
                    fn ($r) => Str::of($r->nombre)->lower()->ascii()->toString() === $normalizado
                );

                if ($registro) {
                    $datos[$campoDestino] = $registro->id;
                } else {
                    $advertencias[] = "{$def['etiqueta']} \"{$valor}\" no encontrado";
                }

                continue;
            }

            if ($campoDestino === 'tipo') {
                $datos[$campoDestino] = Str::lower($valor);
            } elseif (! empty($def['numerico'])) {
                $datos[$campoDestino] = $this->normalizarNumero($valor);
            } else {
                $datos[$campoDestino] = $valor;
            }
        }

        // Defaults (FR-010): p.ej. tipo = 'producto' si no vino mapeado o la celda estaba vacía.
        foreach ($definicion as $campo => $def) {
            if (array_key_exists('default', $def) && ! array_key_exists($campo, $datos)) {
                $datos[$campo] = $def['default'];
            }
        }

        $this->verificarStockTotal($datos, $definicion, $advertencias);

        return [$datos, $advertencias];
    }

    /**
     * "Stock Total" no se persiste (ver DefinicionCamposImportables::productos()) —
     * sólo se usa para avisar si no coincide con la suma de los "Stock: {depósito}"
     * mapeados en la misma fila, por si la planilla trae depósitos que no se
     * mapearon (o un total desactualizado).
     *
     * @param  array<string, mixed>  $datos
     * @param  array<string, array<string, mixed>>  $definicion
     * @param  array<int, string>  $advertencias
     */
    private function verificarStockTotal(array $datos, array $definicion, array &$advertencias): void
    {
        $campoTotal = null;
        foreach ($definicion as $campo => $def) {
            if (! empty($def['solo_verificacion'])) {
                $campoTotal = $campo;
                break;
            }
        }

        if ($campoTotal === null || ! array_key_exists($campoTotal, $datos)) {
            return;
        }

        $suma = 0.0;
        $huboDeposito = false;
        foreach ($definicion as $campo => $def) {
            if (isset($def['deposito_id']) && array_key_exists($campo, $datos)) {
                $suma += (float) $datos[$campo];
                $huboDeposito = true;
            }
        }

        if (! $huboDeposito) {
            return;
        }

        $total = (float) $datos[$campoTotal];
        if (abs($total - $suma) > 0.01) {
            $advertencias[] = "Stock Total ({$total}) no coincide con la suma de los depósitos mapeados ({$suma})";
        }
    }

    /**
     * Acepta números en formato argentino (coma decimal, punto de miles: "1.234,56")
     * además del formato con punto decimal ("1234.56") — Excel/Sheets locales suelen
     * exportar precios/costos con coma decimal, que `is_numeric`/la regla `numeric`
     * de Laravel no reconocen tal cual.
     */
    private function normalizarNumero(string $valor): string
    {
        if (str_contains($valor, ',')) {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        }

        return $valor;
    }

    /**
     * Arma el set de reglas una sola vez por importación (no por fila): las reglas de
     * `ReglasCliente`/`ReglasProveedor`/`ReglasProducto` no dependen de los datos de la
     * fila (siempre asumen alta nueva, `tipo_documento` por defecto), así que
     * reconstruirlas 9000 veces sólo agregaba costo. Los campos FK-por-nombre
     * (Proveedor/Categoría/Condición de IVA/Tipo de Producto) ya se resolvieron contra
     * el catálogo real en `mapearFila()`, así que se les saca la regla `exists` — es
     * una consulta redundante por fila.
     *
     * @param  array<string, array{fk?: array{modelo: class-string, scope?: string}}>  $definicion
     * @return array<string, mixed>
     */
    private function construirReglas(string $entidad, array $definicion): array
    {
        $adaptador = match ($entidad) {
            'clientes' => new ReglasClienteImportacion,
            'proveedores' => new ReglasProveedorImportacion,
            'productos' => new ReglasProductoImportacion,
        };

        $reglas = $adaptador->reglas();

        foreach ($definicion as $campo => $def) {
            if (isset($def['fk'])) {
                unset($reglas[$campo]);
            }
            $esNumericoDinamico = isset($def['lista_precio_id']) || isset($def['deposito_id']) || ! empty($def['solo_verificacion']);
            if ($esNumericoDinamico && ! isset($reglas[$campo])) {
                $reglas[$campo] = ['nullable', 'numeric', 'min:0'];
            }
        }

        return $reglas;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $reglas
     * @return array{0: bool, 1: ?string}
     */
    private function validarFila(array $datos, array $reglas): array
    {
        $validator = Validator::make($datos, $reglas);

        if ($validator->fails()) {
            return [false, $validator->errors()->first()];
        }

        return [true, null];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crear(string $entidad, array $datos, ?User $usuario): void
    {
        if ($entidad === 'productos') {
            $this->crearProducto($datos, $usuario);

            return;
        }

        match ($entidad) {
            'clientes' => Cliente::create($datos),
            'proveedores' => Proveedor::create($datos),
        };
    }

    /**
     * Los campos `precio_lista_{id}`, `stock_deposito_{id}` y
     * `stock_total_verificacion` no son columnas de `productos` — se sacan del
     * payload antes de crear el producto: los primeros se vuelcan en
     * `precios_producto`, los segundos generan un movimiento "Registro inicial"
     * por depósito (mismo camino que el alta manual, `StockService::ajustar()`),
     * y el último ya se usó sólo para la advertencia de `verificarStockTotal()`.
     *
     * @param  array<string, mixed>  $datos
     */
    private function crearProducto(array $datos, ?User $usuario): void
    {
        $precios = [];
        $stockPorDeposito = [];
        foreach ($datos as $campo => $valor) {
            if (preg_match('/^precio_lista_(\d+)$/', $campo, $m)) {
                unset($datos[$campo]);
                if ($valor !== null && $valor !== '') {
                    $precios[(int) $m[1]] = $valor;
                }

                continue;
            }

            if (preg_match('/^stock_deposito_(\d+)$/', $campo, $m)) {
                unset($datos[$campo]);
                if ($valor !== null && $valor !== '' && (float) $valor > 0) {
                    $stockPorDeposito[(int) $m[1]] = (float) $valor;
                }

                continue;
            }

            if ($campo === 'stock_total_verificacion') {
                unset($datos[$campo]);
            }
        }

        $producto = Producto::create($datos);

        foreach ($precios as $listaPrecioId => $precio) {
            $producto->precios()->create([
                'lista_precio_id' => $listaPrecioId,
                'precio' => $precio,
            ]);
        }

        if ($producto->controlaStock()) {
            foreach ($stockPorDeposito as $depositoId => $cantidad) {
                $this->stockService->ajustar(
                    $producto,
                    null,
                    Deposito::findOrFail($depositoId),
                    $cantidad,
                    'Registro inicial (importación)',
                    $usuario,
                );
            }
        }
    }
}
