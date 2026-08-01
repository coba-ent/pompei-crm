<?php

namespace App\Http\Requests\Import;

use App\Http\Requests\Concerns\ReglasCliente;
use Illuminate\Http\Request;

/**
 * Adapta `ReglasCliente` (pensado para un `FormRequest` HTTP) a la validación
 * de una fila de importación: el trait lee con `$this->input(...)`, que
 * `Illuminate\Http\Request` ya resuelve igual sin necesitar un request HTTP
 * real — evita duplicar las reglas de Cliente para el camino de importación.
 */
class ReglasClienteImportacion extends Request
{
    use ReglasCliente;

    /**
     * @return array<string, mixed>
     */
    public function reglas(): array
    {
        return $this->reglasCliente();
    }

    /**
     * Reglas para una fila de actualización (Id mapeado con match): mismo set que el
     * alta, con `ignore($id)` en la unicidad de CUIT (ya soportado por `reglasCliente`)
     * y `nombre` relajado a `nullable` porque una actualización parcial no debería
     * exigir un campo que el cliente ya tiene (research.md §3, FR-006).
     *
     * @return array<string, mixed>
     */
    public function reglasActualizacion(?int $id = null): array
    {
        $reglas = $this->reglasCliente($id);
        $reglas['nombre'] = ['nullable', 'string', 'max:255'];

        return $reglas;
    }
}
