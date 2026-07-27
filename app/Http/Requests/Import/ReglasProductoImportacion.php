<?php

namespace App\Http\Requests\Import;

use App\Http\Requests\Concerns\ReglasProducto;
use Illuminate\Http\Request;

/** Ídem `ReglasClienteImportacion`, para Producto. */
class ReglasProductoImportacion extends Request
{
    use ReglasProducto;

    /**
     * @return array<string, mixed>
     */
    public function reglas(): array
    {
        return $this->reglasProducto();
    }
}
