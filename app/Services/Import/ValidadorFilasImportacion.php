<?php

namespace App\Services\Import;

use App\Http\Requests\Import\ReglasClienteImportacion;
use App\Http\Requests\Import\ReglasProductoImportacion;
use App\Http\Requests\Import\ReglasProveedorImportacion;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Rules\CuitValido;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Dice QUÉ haría el importador con una fila —alta, actualización o error— sin hacerlo.
 *
 * Es la costura que sostiene FR-003 de la spec 083: la prevalidación del modal de confirmación y la
 * importación real comparten estas reglas **por construcción**, no por disciplina. Antes vivían
 * embebidas en `ImportadorFilas`, que las usaba a la vez que escribía.
 *
 * ⚠️ **Invariante I1 del contrato**: este servicio NO tiene forma de escribir. No recibe el
 * `StockService`, no llama a `create()`/`update()`/`save()`. Si alguna vez hace falta que escriba,
 * eso es señal de que el diseño se rompió — no de que haya que pasarle una dependencia más.
 *
 * Ver `specs/083-prevalidacion-importacion/contracts/validador-filas.md`.
 */
final class ValidadorFilasImportacion
{
    /** Contexto por importación (definición, catálogos FK, reglas), memoizado por firma del mapeo. */
    private array $contextos = [];

    /**
     * Qué haría el importador con esta fila, sin hacerlo.
     *
     * @param  array<int, mixed>  $celdas  fila cruda, celdas por índice
     * @param  array<int|string, string>  $mapeo  índice de columna => campo destino
     * @param  array<int|string, string>  $personalizados  índice de columna => nombre del campo personalizado
     * @param  array<int, mixed>  $columnasOriginales  índice de columna => encabezado tal cual vino
     * @return array{modo: 'alta'|'actualizacion'|'error', motivos: array<int, string>, advertencias: array<int, string>, registro_id: int|null, campos: array<int, string>, datos: array<string, mixed>, id_forzado: int|null, registro: Model|null}
     */
    public function evaluar(array $celdas, string $entidad, array $mapeo, array $personalizados = [], array $columnasOriginales = []): array
    {
        $ctx = $this->contexto($entidad, $mapeo, $columnasOriginales);

        [$datos, $advertencias, $erroresCelda, $campos] = $this->mapearFila($celdas, $mapeo, $personalizados, $ctx);

        $base = [
            'modo' => 'error',
            'motivos' => [],
            'advertencias' => $advertencias,
            'registro_id' => null,
            'campos' => $campos,
            'datos' => $datos,
            'id_forzado' => null,
            'registro' => null,
        ];

        // I6: una celda con fórmula no evaluable (o con el texto de la fórmula sin calcular) es
        // error de fila. Nunca se deja pasar el texto como si fuera un valor (FR-012, FR-013).
        if ($erroresCelda !== []) {
            return ['motivos' => $erroresCelda] + $base;
        }

        $resolucion = $this->resolverModoFila($datos, $entidad);

        if ($resolucion['modo'] === 'fallida') {
            return ['motivos' => [$resolucion['motivo']]] + $base;
        }

        if ($resolucion['modo'] === 'actualizacion') {
            $registro = $resolucion['registro'];
            $datosValidar = $datos;
            unset($datosValidar['id']);

            $reglas = $this->ajustarReglaCuit(
                $this->construirReglasActualizacion($entidad, $ctx['definicion'], $registro->id),
                $datosValidar
            );

            $motivos = $this->motivosDeValidacion($datosValidar, $reglas, $ctx);
            if ($motivos !== []) {
                return ['motivos' => $motivos] + $base;
            }

            return [
                'modo' => 'actualizacion',
                'motivos' => [],
                'advertencias' => $advertencias,
                'registro_id' => $registro->id,
                'campos' => $campos,
                'datos' => $datosValidar,
                'id_forzado' => null,
                'registro' => $registro,
            ];
        }

        // Alta: si venía "Id" mapeado pero sin match (spec 027), esa columna no se persiste como
        // campo de negocio — se usa aparte para forzar el id del alta.
        $idForzado = $resolucion['id'] ?? null;
        $datosValidar = $datos;
        unset($datosValidar['id']);

        $motivos = $this->motivosDeValidacion($datosValidar, $this->ajustarReglaCuit($ctx['reglas'], $datosValidar), $ctx);
        if ($motivos !== []) {
            return ['motivos' => $motivos] + $base;
        }

        return [
            'modo' => 'alta',
            'motivos' => [],
            'advertencias' => $advertencias,
            'registro_id' => null,
            'campos' => $campos,
            'datos' => $datosValidar,
            'id_forzado' => $idForzado,
            'registro' => null,
        ];
    }

    /**
     * Contexto de una importación: definición de campos, catálogos FK precargados, reglas de alta y
     * resolución DNI/CUIT por encabezado. Se arma una sola vez y no por fila — con 9.632 filas la
     * diferencia es el orden de magnitud del paso entero.
     *
     * @return array{definicion: array<string, array<string, mixed>>, catalogosFk: array<int|string, Collection>, reglas: array<string, mixed>, tipoPorIndiceCuit: array<int, string>, etiquetas: array<string, string>}
     */
    private function contexto(string $entidad, array $mapeo, array $columnasOriginales): array
    {
        $clave = $entidad.'|'.md5((string) json_encode([$mapeo, array_map(fn ($c) => (string) $c, $columnasOriginales)]));

        if (isset($this->contextos[$clave])) {
            return $this->contextos[$clave];
        }

        $definicion = DefinicionCamposImportables::paraEntidad($entidad);

        return $this->contextos[$clave] = [
            'definicion' => $definicion,
            'catalogosFk' => $this->precargarCatalogosFk($mapeo, $definicion),
            'reglas' => $this->agregarReglasDinamicas($this->adaptadorImportacion($entidad)->reglas(), $definicion),
            'tipoPorIndiceCuit' => $this->resolverTipoPorIndiceCuit($mapeo, $columnasOriginales),
            'etiquetas' => $this->etiquetasDeAtributos($mapeo, $definicion, $columnasOriginales),
        ];
    }

    /**
     * FR-019: el nombre con el que se menciona un campo en un motivo de error sale del **mapeo real**
     * de esta importación — el encabezado tal cual vino en el archivo si lo hay, y si no la etiqueta
     * visible del campo destino. Nunca el nombre interno (`precio_lista_7`), que es lo que el usuario
     * vio en los mensajes del incidente del 25/08.
     *
     * @return array<string, string>
     */
    private function etiquetasDeAtributos(array $mapeo, array $definicion, array $columnasOriginales): array
    {
        $etiquetas = [];

        foreach ($definicion as $campo => $def) {
            $etiquetas[$campo] = $def['etiqueta'];
        }

        foreach ($mapeo as $indice => $campoDestino) {
            if ($campoDestino === '' || $campoDestino === null || $campoDestino === 'personalizado') {
                continue;
            }
            $encabezado = trim((string) ($columnasOriginales[$indice] ?? ''));
            if ($encabezado !== '') {
                $etiquetas[$campoDestino] = $encabezado;
            }
        }

        return $etiquetas;
    }

    /**
     * Mensajes de validación propios del importador, en español (FR-018).
     *
     * No se cambia `APP_LOCALE`: eso afectaría toda la app y es otra feature (research Decisión 4).
     * Las claves son `regla` o `regla.tipo`, igual que `resources/lang`.
     *
     * @return array<string, string>
     */
    private function mensajes(): array
    {
        return [
            'required' => 'Falta completar :attribute.',
            'numeric' => ':attribute tiene que ser un número.',
            'integer' => ':attribute tiene que ser un número entero.',
            'string' => ':attribute tiene que ser texto.',
            'boolean' => ':attribute tiene que ser Sí o No.',
            'date' => ':attribute tiene que ser una fecha válida.',
            'email' => ':attribute no es un email válido.',
            'min.numeric' => ':attribute no puede ser menor a :min.',
            'min.string' => ':attribute tiene que tener al menos :min caracteres.',
            'min.array' => ':attribute tiene que tener al menos :min elementos.',
            'max.numeric' => ':attribute no puede superar :max.',
            'max.string' => ':attribute no puede tener más de :max caracteres.',
            'max.array' => ':attribute no puede tener más de :max elementos.',
            'in' => ':attribute tiene un valor que no está permitido.',
            'not_in' => ':attribute tiene un valor que no está permitido.',
            'unique' => 'Ya existe otro registro con ese :attribute.',
            'exists' => ':attribute no existe.',
            'digits' => ':attribute tiene que tener :digits dígitos.',
            'digits_between' => ':attribute tiene que tener entre :min y :max dígitos.',
            'regex' => ':attribute tiene un formato inválido.',
            'array' => ':attribute tiene un formato inválido.',
            'size.numeric' => ':attribute tiene que ser :size.',
            'size.string' => ':attribute tiene que tener :size caracteres.',
            'gt.numeric' => ':attribute tiene que ser mayor a :value.',
            'gte.numeric' => ':attribute tiene que ser mayor o igual a :value.',
            'lt.numeric' => ':attribute tiene que ser menor a :value.',
            'lte.numeric' => ':attribute tiene que ser menor o igual a :value.',
        ];
    }

    /**
     * I4 (FR-020): **todos** los motivos de la fila, no sólo el primero. Antes se cortaba en
     * `errors()->first()` y el usuario tenía que corregir de a un error por intento.
     *
     * @return array<int, string>
     */
    private function motivosDeValidacion(array $datos, array $reglas, array $ctx): array
    {
        $validator = Validator::make($datos, $reglas, $this->mensajes(), $ctx['etiquetas']);

        if (! $validator->fails()) {
            return [];
        }

        return array_values(array_unique($validator->errors()->all()));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>, 3: array<int, string>}
     *                                                                                                             [datos, advertencias, errores de celda, etiquetas de los campos que esta fila escribiría]
     */
    private function mapearFila(array $celdas, array $mapeo, array $personalizados, array $ctx): array
    {
        $definicion = $ctx['definicion'];
        $catalogosFk = $ctx['catalogosFk'];
        $tipoPorIndiceCuit = $ctx['tipoPorIndiceCuit'];

        $datos = [];
        $advertencias = [];
        $errores = [];
        $campos = [];

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

            $crudo = $celdas[$indice] ?? '';
            $nombreColumna = $ctx['etiquetas'][$campoDestino] ?? ($definicion[$campoDestino]['etiqueta'] ?? $campoDestino);

            $errorCelda = $this->errorDeCelda($crudo, $nombreColumna);
            if ($errorCelda !== null) {
                $errores[] = $errorCelda;

                continue;
            }

            $valor = trim((string) $crudo);
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
                $campos[] = $nombreCampo;

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
                    $campos[] = $def['etiqueta'];
                } else {
                    $advertencias[] = "{$def['etiqueta']} \"{$valor}\" no encontrado";
                }

                continue;
            }

            if ($campoDestino === 'tipo') {
                $datos[$campoDestino] = Str::lower($valor);
            } elseif ($campoDestino === 'email') {
                // Un email mal formado no tira abajo la fila (no es dato fiscal ni de dinero,
                // Principio IV): se omite y se reporta como advertencia.
                if (filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                    $datos[$campoDestino] = $valor;
                } else {
                    $advertencias[] = "Email \"{$valor}\" no es válido, se omitió";

                    continue;
                }
            } elseif ($campoDestino === 'cuit') {
                $datos[$campoDestino] = $this->normalizarCuit($valor);
            } elseif (! empty($def['numerico'])) {
                $datos[$campoDestino] = $this->normalizarNumero($valor);
            } elseif (! empty($def['fecha'])) {
                $datos[$campoDestino] = $this->normalizarFecha($crudo) ?? $valor;
            } elseif (! empty($def['booleano'])) {
                $normalizado = $this->normalizarBooleano($valor);
                $datos[$campoDestino] = $normalizado === null ? $valor : $normalizado;
            } else {
                $datos[$campoDestino] = $valor;
            }

            // I7 (FR-005b): `campos` lista EXACTAMENTE los campos que esta fila escribiría. Los que
            // no se persisten ("Id", "Stock Total (sólo verificación)") quedan afuera: si el modal
            // los sumara, mentiría sobre lo que la importación va a tocar.
            if (empty($def['id']) && empty($def['solo_verificacion'])) {
                $campos[] = $def['etiqueta'];
            }
        }

        // Defaults (FR-010): p.ej. tipo = 'producto' si no vino mapeado o la celda estaba vacía.
        foreach ($definicion as $campo => $def) {
            if (array_key_exists('default', $def) && ! array_key_exists($campo, $datos)) {
                $datos[$campo] = $def['default'];
            }
        }

        $this->verificarStockTotal($datos, $definicion, $advertencias);

        return [$datos, $advertencias, $errores, array_values(array_unique($campos))];
    }

    /**
     * FR-012/FR-013: una celda que el volcado no pudo evaluar, o que llegó con el TEXTO de la
     * fórmula en vez de su resultado, es un error de fila que nombra la columna. Nunca se guarda.
     *
     * El caso real: `Ferrum nuevos (2).xlsx` guardado sin recalcular metió 124 productos con el
     * código puesto en `=CONCATENAR(...)` y el precio en cero.
     */
    private function errorDeCelda(mixed $crudo, string $nombreColumna): ?string
    {
        if (! is_string($crudo)) {
            return null;
        }

        if (str_contains($crudo, FuenteFilasImportacion::MARCA_FORMULA)) {
            return "La columna \"{$nombreColumna}\" tiene una fórmula de Excel que no se pudo calcular. Abrí la planilla, recalculala y volvé a exportarla (o pegá los valores).";
        }

        if (str_starts_with(ltrim($crudo), '=')) {
            return "La columna \"{$nombreColumna}\" trae el texto de una fórmula (\"".Str::limit(trim($crudo), 40).'") en vez de su resultado. Abrí la planilla, recalculala y volvé a exportarla (o pegá los valores).';
        }

        return null;
    }

    /**
     * Resuelve, para una fila ya mapeada, si corresponde alta o actualización según `$datos['id']`
     * (spec 027):
     * - ausente/vacío → alta sin id forzado.
     * - no numérico → fila fallida.
     * - numérico sin match → alta forzando ese id (para que re-importar el mismo archivo matchee).
     * - numérico con match → actualización, con el modelo ya resuelto.
     *
     * @return array{modo: 'alta'|'actualizacion'|'fallida', registro?: Model, motivo?: string, id?: int}
     */
    private function resolverModoFila(array $datos, string $entidad): array
    {
        $valor = $datos['id'] ?? null;
        if ($valor === null || $valor === '') {
            return ['modo' => 'alta'];
        }

        $id = filter_var($valor, FILTER_VALIDATE_INT);
        if ($id === false) {
            return ['modo' => 'fallida', 'motivo' => "La columna Id tiene el valor «{$valor}», que no es un id válido."];
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
     * Precomputa a qué tipo de documento corresponde cada columna mapeada a "cuit" según su
     * encabezado original (spec 028).
     *
     * @return array<int, string> índice de columna => 'DNI'|'CUIT'
     */
    public function resolverTipoPorIndiceCuit(array $mapeo, array $columnasOriginales): array
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
     * Con más de una columna mapeada a "cuit", toma la que tiene valor en esta fila; si las dos
     * tienen, gana la que matchea encabezado "DNI" (spec 028).
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

    /** Mismo saneo que `ReglasCliente::normalizarCuit()` en el alta/edición manual. */
    private function normalizarCuit(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    /**
     * "Stock Total" no se persiste — sólo avisa si no coincide con la suma de los "Stock: {depósito}"
     * mapeados en la misma fila.
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

    /** Acepta números en formato argentino (coma decimal, punto de miles: "1.234,56"). */
    private function normalizarNumero(string $valor): string
    {
        if (str_contains($valor, ',')) {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        }

        return $valor;
    }

    /**
     * Acepta fecha nativa de Excel (número de serie, `DateTime` o string `Y-m-d H:i:s`), texto
     * `DD/MM/YYYY` o texto `YYYY-MM-DD` (research.md §1 de la spec 026). `null` si no matchea:
     * ahí se deja el valor crudo para que la regla `date` produzca el motivo.
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

        // `Carbon::createFromFormat` desborda fechas fuera de rango en vez de fallar: se valida con
        // `checkdate()` en lugar de confiar en ese comportamiento permisivo.
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $valor, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $valor, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $valor)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Acepta `si/no`, `1/0`, `true/false` y `activo/inactivo` — este último es el texto literal que
     * escribe `ProductosExport` para la columna "Estado" (spec 074).
     */
    private function normalizarBooleano(string $valor): ?bool
    {
        $normalizado = Str::of($valor)->lower()->ascii()->trim()->toString();

        return match ($normalizado) {
            'si', '1', 'true', 'activo' => true,
            'no', '0', 'false', 'inactivo' => false,
            default => null,
        };
    }

    /**
     * Catálogos de los campos FK-por-nombre mapeados, precargados una sola vez.
     *
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
     * Reglas de una fila de actualización: mismo mecanismo que las de alta, pero a partir de
     * `reglasActualizacion($id)`. Se reconstruye por fila porque `$id` varía.
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

    private function agregarReglasDinamicas(array $reglas, array $definicion): array
    {
        foreach ($definicion as $campo => $def) {
            if (isset($def['fk'])) {
                // Ya se resolvió contra el catálogo real en mapearFila(): la regla `exists` sería
                // una consulta redundante por fila.
                unset($reglas[$campo]);
            }
            // 'solo_verificacion' (Stock Total) no se valida estricto: no se persiste.
            // Un precio negativo no tiene sentido de negocio, así que las listas conservan `min:0`.
            // El stock por depósito SÍ puede ser negativo (producto sobrevendido, spec 074).
            if (isset($def['lista_precio_id']) && ! isset($reglas[$campo])) {
                $reglas[$campo] = ['nullable', 'numeric', 'min:0'];
            }
            if (isset($def['deposito_id']) && ! isset($reglas[$campo])) {
                $reglas[$campo] = ['nullable', 'numeric'];
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
     * Las reglas se arman una vez por importación a partir de un `Request` vacío, así que el chequeo
     * de dígito verificador queda siempre activo asumiendo CUIT. Cuando la fila resuelve
     * `tipo_documento`, hay que ajustarlo: sólo exigir `CuitValido` si el tipo es CUIT/CUIL — mismo
     * criterio que el alta/edición manual.
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
}
