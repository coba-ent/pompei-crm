<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;
use App\Models\InformeVista;
use App\Services\Informes\DimensionesPivot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vistas guardadas de "Arma tu Informe" (spec 069, US3).
 *
 * Es el ÚNICO punto de escritura del módulo Informes: las tandas 1 y 2 son de sólo lectura. Lo
 * que se guarda es configuración de presentación —qué se cruza con qué—, no datos de negocio.
 *
 * Ventas y Compras comparten esta clase con métodos finos por informe en vez de recibir el
 * informe como parámetro de ruta: así una vista de Ventas no puede listarse ni borrarse desde el
 * endpoint de Compras ni manipulando la URL (FR-035, invariante 6 del data-model).
 */
class InformeVistaController extends Controller
{
    public function __construct(private readonly DimensionesPivot $dimensiones)
    {
    }

    // ---------------------------------------------------------------- Ventas

    public function indexVentas(): JsonResponse
    {
        return $this->index('ventas');
    }

    public function storeVentas(Request $peticion): JsonResponse
    {
        return $this->store($peticion, 'ventas');
    }

    public function updateVentas(Request $peticion, InformeVista $vista): JsonResponse
    {
        return $this->update($peticion, $vista, 'ventas');
    }

    public function destroyVentas(InformeVista $vista): JsonResponse
    {
        return $this->destroy($vista, 'ventas');
    }

    // --------------------------------------------------------------- Compras

    public function indexCompras(): JsonResponse
    {
        return $this->index('compras');
    }

    public function storeCompras(Request $peticion): JsonResponse
    {
        return $this->store($peticion, 'compras');
    }

    public function updateCompras(Request $peticion, InformeVista $vista): JsonResponse
    {
        return $this->update($peticion, $vista, 'compras');
    }

    public function destroyCompras(InformeVista $vista): JsonResponse
    {
        return $this->destroy($vista, 'compras');
    }

    // ------------------------------------------------------------ Compartido

    private function index(string $informe): JsonResponse
    {
        $vistas = InformeVista::porInforme($informe)
            ->with('creadoPor:id,name')
            ->get()
            ->map(fn (InformeVista $v) => [
                'id' => $v->id,
                'descripcion' => $v->descripcion,
                'config' => $v->config,
                'creado_por' => $v->creadoPor?->name,
            ]);

        return response()->json(['data' => $vistas]);
    }

    private function store(Request $peticion, string $informe): JsonResponse
    {
        $datos = $this->validar($peticion, $informe);

        if ($invalida = $this->errorDeCombinacion($informe, $datos)) {
            return $invalida;
        }

        // Nombre repetido: se RECHAZA. Antes se aceptaba avisando, pero al poder editar una vista
        // existente el duplicado dejó de ser un nombre incómodo y pasó a ser una trampa: guardar
        // los cambios de "Ventas por cliente" creaba una SEGUNDA pestaña con ese mismo nombre y
        // después no había forma de saber cuál era la buena (28/08/2026).
        if ($this->nombreRepetido($informe, $datos['descripcion'])) {
            return $this->errorNombreRepetido();
        }

        $vista = InformeVista::create([
            'informe' => $informe,
            'descripcion' => $datos['descripcion'],
            'config' => $this->configNormalizada($datos['config']),
            'creado_por_id' => $peticion->user()?->id,
        ]);

        return response()->json(['data' => $this->comoJson($vista)], 201);
    }

    /**
     * Guarda los cambios SOBRE una vista ya existente (28/08/2026).
     *
     * Antes no existía: el único camino era POST, así que editar un informe guardado y volver a
     * guardarlo creaba uno nuevo en vez de actualizarlo. La descripción viaja igual que en el
     * `store` porque el modal deja renombrar la vista en el mismo paso.
     */
    private function update(Request $peticion, InformeVista $vista, string $informe): JsonResponse
    {
        // Mismo aislamiento que `destroy`: una vista de Compras no se edita desde el endpoint de
        // Ventas ni cambiando el id en la URL.
        abort_if($vista->informe !== $informe, 404);

        $datos = $this->validar($peticion, $informe);

        if ($invalida = $this->errorDeCombinacion($informe, $datos)) {
            return $invalida;
        }

        // Se excluye la propia vista: renombrarla a lo que ya se llamaba no es un duplicado.
        if ($this->nombreRepetido($informe, $datos['descripcion'], $vista->id)) {
            return $this->errorNombreRepetido();
        }

        $vista->update([
            'descripcion' => $datos['descripcion'],
            'config' => $this->configNormalizada($datos['config']),
        ]);

        return response()->json(['data' => $this->comoJson($vista)]);
    }

    /** Reglas compartidas por `store` y `update`. */
    private function validar(Request $peticion, string $informe): array
    {
        return $peticion->validate([
            'descripcion' => ['required', 'string', 'max:255'],
            'config' => ['required', 'array'],
            'config.filas' => ['present', 'array'],
            'config.filas.*' => [Rule::in($this->dimensiones->clavesDimension($informe))],
            'config.columnas' => ['present', 'array'],
            'config.columnas.*' => [Rule::in($this->dimensiones->clavesDimension($informe))],
            'config.dato' => ['required', 'string', Rule::in(array_keys($this->dimensiones->medidas($informe)))],
            'config.accion' => ['required', 'string'],
            'config.exclusiones' => ['nullable', 'array'],
        ], [
            'descripcion.required' => 'Poné una descripción para la vista.',
        ]);
    }

    /**
     * FR-014 en el servidor, no sólo en el cliente: sobre un conteo de filas la única acción con
     * sentido es Suma, y un POST/PUT fuera de la UI podría mandar "promedio".
     */
    private function errorDeCombinacion(string $informe, array $datos): ?JsonResponse
    {
        if ($this->dimensiones->combinacionValida($informe, $datos['config']['dato'], $datos['config']['accion'])) {
            return null;
        }

        return response()->json([
            'message' => 'Esa combinación de Dato y Acción no es válida.',
            'errors' => ['config.accion' => ['La acción elegida no aplica al dato seleccionado.']],
        ], 422);
    }

    private function nombreRepetido(string $informe, string $descripcion, ?int $exceptoId = null): bool
    {
        return InformeVista::porInforme($informe)
            ->where('descripcion', $descripcion)
            ->when($exceptoId, fn ($q) => $q->whereKeyNot($exceptoId))
            ->exists();
    }

    private function errorNombreRepetido(): JsonResponse
    {
        return response()->json([
            'message' => 'Ya existe un informe con ese nombre.',
            'errors' => ['descripcion' => ['Ya existe un informe con ese nombre. Poné otro.']],
        ], 422);
    }

    /** Sólo las claves del contrato: un POST con campos de más no ensucia el JSON guardado. */
    private function configNormalizada(array $config): array
    {
        return [
            'filas' => $config['filas'],
            'columnas' => $config['columnas'],
            'dato' => $config['dato'],
            'accion' => $config['accion'],
            'exclusiones' => $config['exclusiones'] ?? [],
        ];
    }

    private function comoJson(InformeVista $vista): array
    {
        return [
            'id' => $vista->id,
            'descripcion' => $vista->descripcion,
            'config' => $vista->config,
        ];
    }

    private function destroy(InformeVista $vista, string $informe): JsonResponse
    {
        // Una vista de Compras no se borra desde el endpoint de Ventas ni cambiando el id a mano.
        abort_if($vista->informe !== $informe, 404);

        // DELETE real: no es documento fiscal (ver la migración).
        $vista->delete();

        return response()->json(null, 204);
    }
}
