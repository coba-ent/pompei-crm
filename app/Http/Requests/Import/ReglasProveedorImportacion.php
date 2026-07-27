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
}
