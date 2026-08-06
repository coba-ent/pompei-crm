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
use App\Rules\CuitValido;
use App\Services\Stock\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

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
     * @param  array<int, mixed>  $columnasOriginales  índice de columna => encabezado tal cual vino en el archivo (fila 0) — sólo se usa para resolver a qué tipo de documento corresponde cada columna mapeada a "cuit"
     * @param  int  $offset  fila de datos (0-based, sin contar encabezado) desde donde arranca esta tanda — permite procesar el archivo en varias
     *   requests cortas en vez de una sola (el proxy delante de PHP-FPM en el hosting compartido corta la conexión ~60s, muy por debajo de lo que
     *   tarda un archivo de varios miles de filas procesado entero).
     * @param  int|null  $limite  cantidad de filas a procesar en esta tanda; null = todas (comportamiento original, usado por tests/CLI)
     * @return array{importados: int, fallidos: array<int, array{fila: int, motivo: string}>, advertencias: array<int, array{fila: int, motivo: string}>, total: int}
     */
    public function importar(string $entidad, string $rutaCompleta, array $mapeo, array $personalizados, ?User $usuario = null, array $columnasOriginales = [], int $offset = 0, ?int $limite = null): array
    {
        // Procesamiento síncrono sin cola (Assumptions del spec): un archivo de varios
        // miles de filas puede superar el límite por defecto de PHP (max_execution_time).
        set_time_limit(0);

        $definicion = DefinicionCamposImportables::paraEntidad($entidad);
        $filas = (Excel::toArray(null, $rutaCompleta))[0] ?? [];
        $todasLasFilas = array_slice($filas, 1); // fila 0 = encabezados
        $total = count($todasLasFilas);
        $filasDatos = $limite === null ? $todasLasFilas : array_slice($todasLasFilas, $offset, $limite);

        $catalogosFk = $this->precargarCatalogosFk($mapeo, $definicion);
        $reglas = $this->construirReglas($entidad, $definicion);
        $tipoPorIndiceCuit = $this->resolverTipoPorIndiceCuit($mapeo, $columnasOriginales);

        $importados = 0;
        $fallidos = [];
        $advertencias = [];

        foreach ($filasDatos as $i => $celdas) {
            $numeroFila = $offset + $i + 2; // +1 por el encabezado, +1 por ser 1-based

            [$datos, $advertenciasFila] = $this->mapearFila($celdas, $mapeo, $personalizados, $definicion, $catalogosFk, $tipoPorIndiceCuit);

            $resolucion = $this->resolverModoFila($datos, $entidad);
            if ($resolucion['modo'] === 'fallida') {
                $fallidos[] = ['fila' => $numeroFila, 'motivo' => $resolucion['motivo']];

                continue;
            }

            if ($resolucion['modo'] === 'actualizacion') {
                unset($datos['id']);
                $reglasActualizacion = $this->ajustarReglaCuit(
                    $this->construirReglasActualizacion($entidad, $definicion, $resolucion['registro']->id),
                    $datos
                );

                [$valido, $motivo] = $this->validarFila($datos, $reglasActualizacion);
                if (! $valido) {
                    $fallidos[] = ['fila' => $numeroFila, 'motivo' => $motivo];

                    continue;
                }

                // Actualización parcial real: `update()`/`actualizarProducto()` sólo
                // persisten las columnas presentes en $datos (research.md §3, FR-003).
                if ($entidad === 'productos') {
                    $this->actualizarProducto($resolucion['registro'], $datos, $usuario);
                } else {
                    $resolucion['registro']->update($datos);
                }
                $importados++;

                foreach ($advertenciasFila as $motivo) {
                    $advertencias[] = ['fila' => $numeroFila, 'motivo' => $motivo];
                }

                continue;
            }

            // Alta nueva: si venía "Id" mapeado pero sin match (resolverModoFila), esa columna
            // no se persiste como campo de negocio — se usa aparte para forzar el id del alta.
            $idForzado = $resolucion['id'] ?? null;
            unset($datos['id']);

            [$valido, $motivo] = $this->validarFila($datos, $this->ajustarReglaCuit($reglas, $datos));
            if (! $valido) {
                $fallidos[] = ['fila' => $numeroFila, 'motivo' => $motivo];

                continue;
            }

            // Un único INSERT por fila (`crear()` no encadena queries relacionadas) — ya es
            // atómico sin envolverlo en una transacción explícita; evita el costo de un
            // BEGIN/COMMIT extra por cada una de las miles de filas del archivo.
            try {
                $this->crear($entidad, $datos, $usuario, $idForzado);
            } catch (\Illuminate\Database\QueryException $e) {
                // Choque de primary key: el id forzado ya lo tomó otro registro (auto-increment
                // o una fila anterior de esta misma corrida) entre que se resolvió como "no
                // encontrado" y el INSERT. No aborta el resto del archivo (Principio IV).
                $fallidos[] = ['fila' => $numeroFila, 'motivo' => "Id {$idForzado} ya está en uso por otro registro."];

                continue;
            }
            $importados++;

            foreach ($advertenciasFila as $motivo) {
                $advertencias[] = ['fila' => $numeroFila, 'motivo' => $motivo];
            }
        }

        return ['importados' => $importados, 'fallidos' => $fallidos, 'advertencias' => $advertencias, 'total' => $total];
    }

    /**
     * Resuelve, para una fila ya mapeada, si corresponde alta o actualización según
     * `$datos['id']`:
     * - ausente/vacío → alta, sin id forzado (comportamiento de siempre).
     * - no numérico o no entero → fila fallida, sin llegar a `validarFila()`.
     * - numérico entero sin match en la tabla de la entidad → **alta, forzando ese id**
     *   en el registro nuevo (no es una actualización porque no hay nada que
     *   actualizar; se preserva el id para que quede consistente con el sistema de
     *   origen del archivo — ej. re-importar el mismo archivo más adelante ya
     *   matchea como actualización en vez de generar altas duplicadas).
     * - numérico entero con match → actualización, con el modelo ya resuelto (evita
     *   una segunda consulta redundante en el paso de actualización).
     *
     * @param  array<string, mixed>  $datos
     * @return array{modo: 'alta'|'actualizacion'|'fallida', registro?: \Illuminate\Database\Eloquent\Model, motivo?: string, id?: int}
     */
    private function resolverModoFila(array $datos, string $entidad): array
    {
        $valor = $datos['id'] ?? null;
        if ($valor === null || $valor === '') {
            return ['modo' => 'alta'];
        }

        $id = filter_var($valor, FILTER_VALIDATE_INT);
        if ($id === false) {
            return ['modo' => 'fallida', 'motivo' => "Id \"{$valor}\" no es un id válido"];
        }

        $modelo = match ($entidad) {
            'clientes' => Cliente::class,
            'proveedores' => Proveedor::class,
            'productos' => Producto::class,
        };

        $registro = $modelo::find($id);
        if (! $registro) {
            return ['modo' => 'alta', 'id' => $id];
        }

        return ['modo' => 'actualizacion', 'registro' => $registro];
    }

    /**
     * Precomputa, una sola vez por importación, a qué tipo de documento corresponde
     * cada columna mapeada al campo "cuit" según su encabezado original en el
     * archivo — spec 028. Sólo importa cuando hay más de un índice (0 o 1 columna
     * mapeada sigue el camino genérico de `mapearFila()`, sin este mecanismo).
     *
     * @param  array<int|string, string>  $mapeo
     * @param  array<int, mixed>  $columnasOriginales
     * @return array<int, string> índice de columna => 'DNI'|'CUIT'
     */
    private function resolverTipoPorIndiceCuit(array $mapeo, array $columnasOriginales): array
    {
        $tipos = [];

        foreach ($mapeo as $indice => $campoDestino) {
            if ($campoDestino !== 'cuit') {
                continue;
            }

            $encabezado = Str::of((string) ($columnasOriginales[$indice] ?? ''))->lower()->ascii()->trim()->toString();
            $tipos[$indice] = $encabezado === 'dni' ? 'DNI' : 'CUIT';
        }

        return $tipos;
    }

    /**
     * Resuelve, para una fila con más de una columna mapeada a "cuit", qué valor y
     * `tipo_documento` corresponden: toma la columna (de las mapeadas) que tiene
     * valor no vacío en esta fila; si las dos tienen valor, gana la que matchea
     * encabezado "DNI" (spec 028, caso de datos sucios — en la práctica no debería
     * darse, según el usuario, las columnas son mutuamente excluyentes por fila).
     *
     * @param  array<int, mixed>  $celdas
     * @param  array<int, string>  $tipoPorIndiceCuit
     * @param  array<string, mixed>  $datos
     */
    private function resolverDocumento(array $celdas, array $tipoPorIndiceCuit, array &$datos): void
    {
        $conValor = [];
        foreach ($tipoPorIndiceCuit as $indice => $tipo) {
            $valor = trim((string) ($celdas[$indice] ?? ''));
            if ($valor !== '') {
                $conValor[$indice] = $valor;
            }
        }

        if (! $conValor) {
            return;
        }

        $indiceElegido = null;
        foreach ($conValor as $indice => $valor) {
            if ($tipoPorIndiceCuit[$indice] === 'DNI') {
                $indiceElegido = $indice;
                break;
            }
        }
        $indiceElegido ??= array_key_first($conValor);

        $datos['cuit'] = $this->normalizarCuit($conValor[$indiceElegido]);
        $datos['tipo_documento'] = $tipoPorIndiceCuit[$indiceElegido];
    }

    /**
     * Saca todo lo que no sea dígito (guiones, espacios, puntos) — mismo saneo que
     * `ReglasCliente::normalizarCuit()` en el alta/edición manual, necesario acá porque
     * el importador nunca pasa por ese método (no es un `FormRequest` HTTP real).
     */
    private function normalizarCuit(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    /**
     * Catálogos de los campos FK-por-nombre mapeados, precargados una sola vez
     * (no por fila) — research.md §3.
     *
     * @param  array<int|string, string>  $mapeo
     * @param  array<string, array{fk?: array{modelo: class-string, scope?: string}}>  $definicion
     * @return array<int|string, Collection>
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
     * @param  array<int|string, Collection>  $catalogosFk
     * @param  array<int, string>  $tipoPorIndiceCuit  índice de columna => 'DNI'|'CUIT', sólo con >1 entrada cuando hay 2 columnas mapeadas a "cuit" (spec 028)
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function mapearFila(array $celdas, array $mapeo, array $personalizados, array $definicion, array $catalogosFk, array $tipoPorIndiceCuit = []): array
    {
        $datos = [];
        $advertencias = [];

        if (count($tipoPorIndiceCuit) > 1) {
            $this->resolverDocumento($celdas, $tipoPorIndiceCuit, $datos);
        }

        foreach ($mapeo as $indice => $campoDestino) {
            if ($campoDestino === '' || $campoDestino === null) {
                continue;
            }

            if ($campoDestino === 'cuit' && count($tipoPorIndiceCuit) > 1) {
                continue; // ya resuelto arriba entre las columnas mapeadas a "cuit"
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
            } elseif ($campoDestino === 'email') {
                // Un email mal formado no es motivo para tirar abajo toda la fila (no es
                // dato fiscal ni de dinero, Principio IV): se omite y se reporta como
                // advertencia, mismo criterio que un Proveedor/Categoría no encontrado.
                if (filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                    $datos[$campoDestino] = $valor;
                } else {
                    $advertencias[] = "Email \"{$valor}\" no es válido, se omitió";
                }
            } elseif ($campoDestino === 'cuit') {
                // Mismo saneo que el alta/edición manual (ReglasCliente::normalizarCuit()):
                // saca guiones/espacios/puntos antes de validar el dígito verificador — un
                // CUIT con formato ("30-69323148-1") no son 11 dígitos puros para la regex de
                // CuitValido si no se limpia primero.
                $datos[$campoDestino] = $this->normalizarCuit($valor);
            } elseif (! empty($def['numerico'])) {
                $datos[$campoDestino] = $this->normalizarNumero($valor);
            } elseif (! empty($def['fecha'])) {
                $normalizada = $this->normalizarFecha($valor);
                $datos[$campoDestino] = $normalizada ?? $valor;
            } elseif (! empty($def['booleano'])) {
                $normalizado = $this->normalizarBooleano($valor);
                if ($normalizado === null) {
                    $datos[$campoDestino] = $valor;
                } else {
                    $datos[$campoDestino] = $normalizado;
                }
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
     * Acepta fecha nativa de Excel (entregada por `Excel::toArray()` como número de
     * serie — `PhpSpreadsheet\Shared\Date::excelToDateTimeObject()` — o como
     * `DateTime`/`Carbon`/string `Y-m-d H:i:s` según el driver), texto `DD/MM/YYYY` o
     * texto `YYYY-MM-DD` (research.md §1). Devuelve `Y-m-d` o `null` si el valor no
     * matchea ningún formato aceptado — en ese caso se deja el valor crudo para que la
     * regla `date` de `construirReglas()` produzca el motivo de fallo.
     */
    private function normalizarFecha(mixed $valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return Carbon::instance($valor)->format('Y-m-d');
        }

        $valor = trim((string) $valor);

        if (is_numeric($valor)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $valor))->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        // `Carbon::createFromFormat` desborda fechas fuera de rango (p.ej. "32/13/2026"
        // se interpreta corriendo meses/días hacia adelante) en vez de fallar — se valida
        // con `checkdate()` en lugar de confiar en ese comportamiento permisivo.
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $valor, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $valor, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        // Fecha nativa de Excel entregada por Excel::toArray() como string "Y-m-d H:i:s".
        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $valor)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Acepta `si/no`, `1/0`, `true/false` (sin distinguir mayúsculas/acentos) —
     * research.md §2. Devuelve `null` si el valor no matchea ninguno.
     */
    private function normalizarBooleano(string $valor): ?bool
    {
        $normalizado = Str::of($valor)->lower()->ascii()->trim()->toString();

        return match ($normalizado) {
            'si', '1', 'true' => true,
            'no', '0', 'false' => false,
            default => null,
        };
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
        return $this->agregarReglasDinamicas($this->adaptadorImportacion($entidad)->reglas(), $definicion);
    }

    /**
     * Reglas de una fila de actualización (registro ya resuelto por `resolverModoFila()`):
     * mismo mecanismo que `construirReglas()`, pero a partir de `reglasActualizacion($id)`
     * (obligatoriedad relajada + `ignore($id)`/`SkuUnico($id, $id)` en unicidad). Se
     * reconstruye por fila (no una vez por importación) porque `$id` varía por fila —
     * research.md §4; el volumen de filas de actualización por corrida es bajo.
     *
     * @param  array<string, array<string, mixed>>  $definicion
     * @return array<string, mixed>
     */
    private function construirReglasActualizacion(string $entidad, array $definicion, int $id): array
    {
        return $this->agregarReglasDinamicas($this->adaptadorImportacion($entidad)->reglasActualizacion($id), $definicion);
    }

    private function adaptadorImportacion(string $entidad): ReglasClienteImportacion|ReglasProveedorImportacion|ReglasProductoImportacion
    {
        return match ($entidad) {
            'clientes' => new ReglasClienteImportacion,
            'proveedores' => new ReglasProveedorImportacion,
            'productos' => new ReglasProductoImportacion,
        };
    }

    /**
     * @param  array<string, mixed>  $reglas
     * @param  array<string, array<string, mixed>>  $definicion
     * @return array<string, mixed>
     */
    private function agregarReglasDinamicas(array $reglas, array $definicion): array
    {
        foreach ($definicion as $campo => $def) {
            if (isset($def['fk'])) {
                unset($reglas[$campo]);
            }
            // 'solo_verificacion' (Stock Total) no se valida estricto: no se persiste, sólo se usa
            // como chequeo cruzado en verificarStockTotal() — un valor sucio (texto, negativo por
            // redondeo de Excel) no puede tirar abajo la fila entera por un campo que ni se guarda.
            $esNumericoDinamico = isset($def['lista_precio_id']) || isset($def['deposito_id']);
            if ($esNumericoDinamico && ! isset($reglas[$campo])) {
                $reglas[$campo] = ['nullable', 'numeric', 'min:0'];
            }
            if (! empty($def['fecha']) && ! isset($reglas[$campo])) {
                $reglas[$campo] = ['nullable', 'date'];
            }
            if (! empty($def['booleano']) && ! isset($reglas[$campo])) {
                $reglas[$campo] = ['nullable', 'boolean'];
            }
        }

        return $reglas;
    }

    /**
     * `construirReglas()`/`construirReglasActualizacion()` arman la regla de "cuit" una
     * sola vez por importación, a partir de un `Request` vacío — por eso el chequeo de
     * dígito verificador (`CuitValido`) queda siempre activo, asumiendo `tipo_documento`
     * por defecto (CUIT). Cuando `$datos['tipo_documento']` viene resuelto para esta fila
     * (columna "Tipo de Documento" mapeada, o resolución por encabezado de spec 028), hay
     * que ajustar esa regla por fila: sólo exigir `CuitValido` si el tipo resuelto es
     * CUIT/CUIL — mismo criterio que ya aplica `ReglasCliente::reglasCliente()` en el
     * alta/edición manual (con un `Request` real, donde `$this->input('tipo_documento')`
     * sí refleja el valor correcto).
     *
     * @param  array<string, mixed>  $reglas
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function ajustarReglaCuit(array $reglas, array $datos): array
    {
        if (! array_key_exists('tipo_documento', $datos) || ! isset($reglas['cuit'])) {
            return $reglas;
        }

        $tipoDoc = strtoupper((string) $datos['tipo_documento']);
        $necesitaCuitValido = in_array($tipoDoc, ['CUIT', 'CUIL'], true);
        $tieneCuitValido = collect($reglas['cuit'])->contains(fn ($regla) => $regla instanceof CuitValido);

        if ($necesitaCuitValido === $tieneCuitValido) {
            return $reglas;
        }

        $reglas['cuit'] = collect($reglas['cuit'])->reject(fn ($regla) => $regla instanceof CuitValido)->values()->all();
        if ($necesitaCuitValido) {
            $reglas['cuit'][] = new CuitValido;
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
     * @param  int|null  $idForzado  cuando la fila traía "Id" mapeado pero sin match (alta con id
     *   preservado del sistema de origen — ver `resolverModoFila()`), se fuerza ese id en el
     *   `INSERT` en vez de dejar que `AUTO_INCREMENT` asigne uno nuevo. `create()` no sirve para
     *   esto porque `id` no es `fillable`; se arma el modelo con `forceFill()` (bypassea esa guarda
     *   sólo para el `id`, el resto de `$datos` ya pasó por `validarFila()`).
     */
    private function crear(string $entidad, array $datos, ?User $usuario, ?int $idForzado = null): void
    {
        if ($entidad === 'productos') {
            $this->crearProducto($datos, $usuario, $idForzado);

            return;
        }

        $claseModelo = match ($entidad) {
            'clientes' => Cliente::class,
            'proveedores' => Proveedor::class,
        };

        if ($idForzado === null) {
            $claseModelo::create($datos);

            return;
        }

        (new $claseModelo)->forceFill($datos + ['id' => $idForzado])->save();
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
    /**
     * Saca del payload las claves que no son columnas reales de `productos`
     * (`precio_lista_{id}`, `stock_deposito_{id}`, `stock_total_verificacion`) —
     * comparten este parseo el alta y la actualización.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: array<int, mixed>, 1: array<int, float>} [precios por lista_precio_id, stock por deposito_id]
     */
    private function extraerPreciosYStock(array &$datos): array
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
                if ($valor !== null && $valor !== '') {
                    $stockPorDeposito[(int) $m[1]] = (float) $valor;
                }

                continue;
            }

            if ($campo === 'stock_total_verificacion') {
                unset($datos[$campo]);
            }
        }

        return [$precios, $stockPorDeposito];
    }

    private function crearProducto(array $datos, ?User $usuario, ?int $idForzado = null): void
    {
        [$precios, $stockPorDeposito] = $this->extraerPreciosYStock($datos);

        if ($idForzado === null) {
            $producto = Producto::create($datos);
        } else {
            $producto = new Producto;
            $producto->forceFill($datos + ['id' => $idForzado]);
            $producto->save();
        }

        foreach ($precios as $listaPrecioId => $precio) {
            $producto->precios()->create([
                'lista_precio_id' => $listaPrecioId,
                'precio' => $precio,
            ]);
        }

        if ($producto->controlaStock()) {
            foreach ($stockPorDeposito as $depositoId => $cantidad) {
                if ($cantidad <= 0) {
                    continue;
                }
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

    /**
     * Camino de actualización para productos (spec de edición masiva vía Excel):
     * el mismo tratamiento que `crearProducto()` para `precio_lista_*`/`stock_deposito_*`,
     * pero contra un producto existente — upsert por lista de precio en vez de `create()`
     * (evita duplicar filas en `precios_producto` si ya tenía precio en esa lista), y
     * ajuste de stock por diferencia contra la cantidad actual (la planilla trae el
     * valor final deseado, no un delta) vía `StockService::ajustar()` para que quede
     * también el `MovimientoStock` correspondiente.
     *
     * @param  array<string, mixed>  $datos
     */
    private function actualizarProducto(Producto $producto, array $datos, ?User $usuario): void
    {
        [$precios, $stockPorDeposito] = $this->extraerPreciosYStock($datos);

        $producto->update($datos);

        foreach ($precios as $listaPrecioId => $precio) {
            $producto->precios()->updateOrCreate(
                ['lista_precio_id' => $listaPrecioId],
                ['precio' => $precio],
            );
        }

        if ($producto->controlaStock()) {
            foreach ($stockPorDeposito as $depositoId => $cantidadDeseada) {
                $deposito = Deposito::findOrFail($depositoId);
                $actual = $this->stockService->disponibilidad($producto, null, $deposito);
                $diferencia = $cantidadDeseada - $actual;
                if ($diferencia === 0.0) {
                    continue;
                }
                $this->stockService->ajustar(
                    $producto,
                    null,
                    $deposito,
                    $diferencia,
                    'Ajuste (importación)',
                    $usuario,
                );
            }
        }
    }
}
