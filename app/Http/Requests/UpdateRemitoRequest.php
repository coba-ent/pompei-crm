<?php

namespace App\Http\Requests;

class UpdateRemitoRequest extends StoreRemitoRequest
{
    // FR-016: la edición usa exactamente las mismas reglas que el alta — ningún campo bloqueado.
}
