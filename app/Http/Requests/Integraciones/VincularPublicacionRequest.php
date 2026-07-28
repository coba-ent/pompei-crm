<?php

namespace App\Http\Requests\Integraciones;

use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Services\MercadoLibre\ClienteMercadoLibre;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Alta/edición de la vinculación publicación↔producto (FR-021..FR-027). La
 * cardinalidad 1:1 se valida acá con mensaje amable, pero la garantía real
 * son los dos índices únicos de la migración (data-model.md §4) — esta
 * validación es sólo la primera línea de defensa.
 */
class VincularPublicacionRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'No se pudo guardar la vinculación, revisá los datos.',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vinculacionId = $this->route('vinculacion')?->id;

        return [
            'ml_item_id' => [
                'required', 'string', 'max:40',
                Rule::unique('ml_publicacion_producto', 'ml_item_id')->ignore($vinculacionId),
            ],
            'producto_id' => [
                'required', 'exists:productos,id',
                Rule::unique('ml_publicacion_producto', 'producto_id')->ignore($vinculacionId),
            ],
            'titulo_ml' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'ml_item_id.unique' => 'Esta publicación ya está vinculada a otro producto.',
            'producto_id.unique' => 'Este producto ya está vinculado a otra publicación.',
        ];
    }

    /**
     * FR-027: rechazar publicaciones con variantes. Sólo se puede saber a
     * partir de líneas de órdenes ya sincronizadas (no hay un endpoint de
     * catálogo en alcance de esta spec) — es una defensa best-effort; la
     * garantía real está en EvaluadorConvertibilidad al momento de convertir.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mlItemId = $this->input('ml_item_id');

            if (! $mlItemId) {
                return;
            }

            $tieneVariante = MercadoLibreOrdenItem::where('ml_item_id', $mlItemId)
                ->whereNotNull('ml_variation_id')
                ->exists();

            if ($tieneVariante) {
                $validator->errors()->add('ml_item_id', 'Las publicaciones con variantes no están soportadas.');
            }

            $this->validarPublicacionEnMercadoLibre($validator, $mlItemId);
        });
    }

    /**
     * Verifica contra la API real que la publicación exista y pertenezca a la
     * cuenta de Mercado Libre conectada en este momento. Sin este chequeo, se
     * puede vincular cualquier ID (inexistente o de otra cuenta) sin ningún
     * aviso, y la vinculación nunca va a matchear ninguna orden real.
     */
    private function validarPublicacionEnMercadoLibre(Validator $validator, string $mlItemId): void
    {
        $respuesta = app(ClienteMercadoLibre::class)->obtener('validar_publicacion', "/items/{$mlItemId}");

        if ($respuesta->fueBloqueada()) {
            // Función desactivada o modo sólo lectura: no hay forma de verificar ahora.
            // No se bloquea el alta por esto (sería peor no poder vincular nunca);
            // sólo se documenta la limitación al usuario.
            $validator->errors()->add('ml_item_id', 'No se pudo verificar la publicación en Mercado Libre ahora mismo (función desactivada o modo sólo lectura). Activalos y volvé a intentar.');

            return;
        }

        if ($respuesta->fallo()) {
            $mensaje = $respuesta->codigoHttp === 404
                ? 'No existe ninguna publicación con ese ID en Mercado Libre.'
                : 'No se pudo verificar la publicación en Mercado Libre: '.$respuesta->mensajeError;

            $validator->errors()->add('ml_item_id', $mensaje);

            return;
        }

        $cuenta = MercadoLibreCuenta::conectada()->first();
        $sellerId = $respuesta->datos['seller_id'] ?? null;

        if ($cuenta && $sellerId && (string) $sellerId !== (string) $cuenta->ml_user_id) {
            $validator->errors()->add('ml_item_id', 'Esta publicación no pertenece a la cuenta de Mercado Libre conectada ('.$cuenta->nickname.').');
        }
    }
}
