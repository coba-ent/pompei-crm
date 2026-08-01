<?php

namespace App\Http\Requests\Import;

use App\Http\Requests\Concerns\ReglasProveedor;
use Illuminate\Http\Request;

/** Ídem `ReglasClienteImportacion`, para Proveedor. */
class ReglasProveedorImportacion extends Request
{
    use ReglasProveedor;

    /**
     * @return array<string, mixed>
     */
    public function reglas(): array
    {
        return $this->reglasProveedor();
    }

    /**
     * Ídem `ReglasClienteImportacion::reglasActualizacion()`, para Proveedor.
     *
     * @return array<string, mixed>
     */
    public function reglasActualizacion(?int $id = null): array
    {
        $reglas = $this->reglasProveedor($id);
        $reglas['nombre'] = ['nullable', 'string', 'max:255'];

        return $reglas;
    }
}
