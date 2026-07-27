<?php

namespace App\Http\Requests;

use App\Models\Producto;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AccionMasivaProductoRequest extends FormRequest
{
    /** Claves soportadas por el select "Elegí una Acción" (orden relevado, contracts/acciones-masivas-rutas.md). */
    public const ACCIONES = [
        'precio_venta',
        'costo',
        'mostrar_ventas',
        'no_mostrar_ventas',
        'mostrar_compras',
        'no_mostrar_compras',
        'activo',
        'iva',
        'tipo_producto_id',
        'proveedor_id',
        'eliminar',
    ];

    /**
     * Acciones con modal propio en Contagram real (capturas/acciones masivas), con payload
     * estructurado distinto del genérico `valor` — ver ProductoController::accionesMasivas().
     */
    private const ACCIONES_PRECIO = ['precio_venta', 'costo'];

    /** Acciones que no piden un valor adicional (se aplican directo al confirmar). */
    private const ACCIONES_SIN_VALOR = [
        'mostrar_ventas', 'no_mostrar_ventas', 'mostrar_compras', 'no_mostrar_compras', 'eliminar',
    ];

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
        $comunes = [
            'accion' => ['required', Rule::in(self::ACCIONES)],
            'ids' => [
                function ($attribute, $value, $fail) {
                    if (! $this->boolean('todos') && empty($value)) {
                        $fail('El campo ids es obligatorio.');
                    }
                },
                'nullable', 'array',
            ],
            'ids.*' => ['integer', 'exists:productos,id'],
            'todos' => ['nullable', 'boolean'],
            'filtros' => ['nullable', 'array'],
        ];

        $accion = $this->input('accion');

        if (in_array($accion, self::ACCIONES_PRECIO, true)) {
            return $comunes + [
                'modo' => ['required', Rule::in(['porcentaje', 'fijo'])],
                'redondear' => ['nullable', 'boolean'],
                'campos' => ['required', 'array', 'min:1'],
                'campos.*.valor' => ['required', 'numeric'],
                'campos.*.signo' => ['required', Rule::in(['aumentar', 'disminuir'])],
            ];
        }

        if ($accion === 'iva') {
            return $comunes + [
                'valor_venta' => ['nullable', Rule::in(array_keys(Producto::OPCIONES_IVA))],
                'valor_compra' => ['nullable', Rule::in(array_keys(Producto::OPCIONES_IVA))],
            ];
        }

        if ($accion === 'tipo_producto_id') {
            return $comunes + [
                'valor_producto' => ['nullable', 'integer', 'exists:tipos_producto,id'],
                'valor_servicio' => ['nullable', 'integer', 'exists:tipos_producto,id'],
            ];
        }

        return $comunes + ['valor' => $this->reglasValor($accion)];
    }

    /**
     * @return array<int, mixed>
     */
    private function reglasValor(?string $accion): array
    {
        if (in_array($accion, self::ACCIONES_SIN_VALOR, true)) {
            return ['nullable'];
        }

        return match ($accion) {
            'activo' => ['required', 'boolean'],
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            default => ['nullable'],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $accion = $this->input('accion');

            if ($accion === 'iva' && ! $this->filled('valor_venta') && ! $this->filled('valor_compra')) {
                $validator->errors()->add('valor_venta', 'Elegí al menos un IVA (Venta o Compra).');
            }

            if ($accion === 'tipo_producto_id' && ! $this->filled('valor_producto') && ! $this->filled('valor_servicio')) {
                $validator->errors()->add('valor_producto', 'Elegí al menos un Tipo (Producto o Servicio).');
            }
        });
    }
}
