<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasProducto;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateProductoRequest extends FormRequest
{
    use ReglasProducto;

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'errors' => $validator->errors(),
        ], 422));
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Ignora el propio producto para no chocar consigo mismo al editar.
        return $this->reglasProducto($this->route('producto')?->id);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn ($v) => $this->validarSkuEnPayload($v));
    }
}
