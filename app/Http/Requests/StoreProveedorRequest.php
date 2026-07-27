<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasProveedor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreProveedorRequest extends FormRequest
{
    use ReglasProveedor;

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
        return $this->reglasProveedor();
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarCuit();
    }
}
