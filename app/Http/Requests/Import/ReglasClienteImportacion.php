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
}
