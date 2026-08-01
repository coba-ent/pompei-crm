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

    /**
     * Ídem `ReglasClienteImportacion::reglasActualizacion()`, para Producto —
     * relaja además `tipo` (también `required` en el alta) a `nullable` (research.md §3).
     *
     * @return array<string, mixed>
     */
    public function reglasActualizacion(?int $id = null): array
    {
        $reglas = $this->reglasProducto($id);
        $reglas['nombre'] = ['nullable', 'string', 'max:255'];
        $reglas['tipo'] = ['nullable', 'in:producto,servicio'];

        return $reglas;
    }
}
