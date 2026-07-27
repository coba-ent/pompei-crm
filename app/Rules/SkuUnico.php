<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Unicidad global de SKU: un código no vacío debe ser único considerando la
 * unión de `productos.codigo` y `producto_variantes.sku` (FR-010). Ignora los
 * valores NULL/vacíos y, opcionalmente, el propio registro (para no chocar
 * consigo mismo al editar).
 */
class SkuUnico implements ValidationRule
{
    public function __construct(
        private ?int $ignorarProductoId = null,
        private ?int $ignorarVariantesDeProductoId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $sku = is_string($value) ? trim($value) : $value;

        // NULL/vacío nunca colisiona (FR-010).
        if ($sku === null || $sku === '') {
            return;
        }

        $existeEnProductos = DB::table('productos')
            ->where('codigo', $sku)
            ->when($this->ignorarProductoId, fn ($q) => $q->where('id', '!=', $this->ignorarProductoId))
            ->exists();

        $existeEnVariantes = DB::table('producto_variantes')
            ->where('sku', $sku)
            ->when($this->ignorarVariantesDeProductoId, fn ($q) => $q->where('producto_id', '!=', $this->ignorarVariantesDeProductoId))
            ->exists();

        if ($existeEnProductos || $existeEnVariantes) {
            $fail('El código/SKU "'.$sku.'" ya está en uso.');
        }
    }
}
