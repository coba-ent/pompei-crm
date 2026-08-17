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
        $datos = $peticion->validate([
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

        // FR-014 en el servidor, no sólo en el cliente: sobre un conteo de filas la única acción
        // con sentido es Suma, y un POST fuera de la UI podría mandar "promedio".
        if (! $this->dimensiones->combinacionValida($informe, $datos['config']['dato'], $datos['config']['accion'])) {
            return response()->json([
                'message' => 'Esa combinación de Dato y Acción no es válida.',
                'errors' => ['config.accion' => ['La acción elegida no aplica al dato seleccionado.']],
            ], 422);
        }

        // Descripción repetida: se ACEPTA y se avisa (contrato de endpoints). Dos personas pueden
        // llamar igual a cruces distintos; bloquear el guardado sería peor que el nombre repetido.
        $repetida = InformeVista::porInforme($informe)->where('descripcion', $datos['descripcion'])->exists();

        $vista = InformeVista::create([
            'informe' => $informe,
            'descripcion' => $datos['descripcion'],
            'config' => [
                'filas' => $datos['config']['filas'],
                'columnas' => $datos['config']['columnas'],
                'dato' => $datos['config']['dato'],
                'accion' => $datos['config']['accion'],
                'exclusiones' => $datos['config']['exclusiones'] ?? [],
            ],
            'creado_por_id' => $peticion->user()?->id,
        ]);

        return response()->json(array_filter([
            'data' => [
                'id' => $vista->id,
                'descripcion' => $vista->descripcion,
                'config' => $vista->config,
            ],
            'aviso' => $repetida ? 'Ya existe una vista con ese nombre.' : null,
        ]), 201);
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
