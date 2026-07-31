<?php

namespace App\Http\Requests\Integraciones;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Valida el archivo de productos exportado nativamente desde Tiendanube antes
 * de procesar ninguna fila (FR-015): archivo vacío, extensión no soportada, o
 * sin las columnas `SKU` / `Identificador de URL` reconocibles por encabezado
 * (research.md R6 — nunca por posición fija, Tiendanube puede reordenar
 * columnas). Mismo patrón que SubirArchivoImportacionRequest (spec 006).
 */
class ImportarVinculacionesTiendanubeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // El export real de Tiendanube es texto plano separado por ";" (research.md
        // R6): la detección de mime por contenido lo identifica como "text/plain",
        // no "text/csv" — se acepta también la extensión "txt" para no rechazar el
        // archivo real por esta ambigüedad de sniffing (mismo contenido, mismo caso).
        return [
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('archivo')) {
                return;
            }

            if (! $this->hasFile('archivo') || ! $this->file('archivo')->isValid()) {
                return;
            }

            // Un archivo de 0 bytes (o con contenido irreconocible) pasa la regla
            // "mimes" por extensión pero PhpSpreadsheet no puede identificar un
            // lector para él — se traduce al mismo error de "archivo vacío".
            try {
                $filas = (Excel::toArray(null, $this->file('archivo')->getRealPath()))[0] ?? [];
            } catch (Throwable $e) {
                $validator->errors()->add('archivo', 'El archivo está vacío o no se pudo leer.');

                return;
            }

            $encabezados = array_map(fn ($v) => trim((string) $v), $filas[0] ?? []);

            if (count($filas) < 2 || $encabezados === []) {
                $validator->errors()->add('archivo', 'El archivo está vacío.');

                return;
            }

            if (! in_array('SKU', $encabezados, true)) {
                $validator->errors()->add('archivo', 'El archivo no tiene una columna "SKU" reconocible.');
            }

            if (! in_array('Identificador de URL', $encabezados, true)) {
                $validator->errors()->add('archivo', 'El archivo no tiene una columna "Identificador de URL" reconocible.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'No se pudo importar el archivo.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
