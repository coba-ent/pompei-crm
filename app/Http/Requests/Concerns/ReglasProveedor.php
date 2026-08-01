<?php

namespace App\Http\Requests\Concerns;

use App\Rules\CuitValido;

/**
 * Reglas de validación compartidas entre alta y edición de proveedor.
 * Clon de ReglasCliente sin `apodo_ml`/`lista_precio_id`/`descuento_general_pct`.
 * El CUIT/DNI NO es único entre proveedores (ver nota en ReglasCliente, misma
 * decisión 31/07/2026 aplicada a ambas entidades por consistencia).
 */
trait ReglasProveedor
{
    /**
     * @return array<string, mixed>
     */
    protected function reglasProveedor(?int $proveedorId = null): array
    {
        $tipoDoc = strtoupper((string) $this->input('tipo_documento', 'CUIT'));

        $reglaDoc = ['nullable', 'string', 'max:20'];
        if (in_array($tipoDoc, ['CUIT', 'CUIL'], true)) {
            $reglaDoc[] = new CuitValido;
        }

        return [
            // Datos básicos
            'nombre' => ['required', 'string', 'max:255'],
            'nombre_pila' => ['nullable', 'string', 'max:255'],
            'apellido' => ['nullable', 'string', 'max:255'],
            'pagina_web' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'telefono_celular' => ['nullable', 'string', 'max:50'],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'localidad' => ['nullable', 'string', 'max:120'],
            'provincia' => ['nullable', 'string', 'max:120'],
            'cp' => ['nullable', 'string', 'max:20'],
            'nota' => ['nullable', 'string', 'max:1000'],

            // Compras
            'categoria_id' => ['nullable', 'exists:categorias,id'],
            'nota_interna' => ['nullable', 'string', 'max:1000'],
            'saldo_inicial' => ['nullable', 'numeric'],
            'saldo_inicial_fecha' => ['nullable', 'date'],

            // Datos de facturación
            'razon_social' => ['nullable', 'string', 'max:255'],
            'tipo_documento' => ['nullable', 'in:CUIT,CUIL,DNI,Pasaporte,CDI'],
            'cuit' => $reglaDoc,
            'condicion_iva_id' => ['nullable', 'exists:condiciones_iva,id'],
            'tipo_comprobante_defecto' => ['nullable', 'in:A,B,C,E'],
            'domicilio_fiscal' => ['nullable', 'string', 'max:255'],
            'localidad_fiscal' => ['nullable', 'string', 'max:120'],
            'provincia_fiscal' => ['nullable', 'string', 'max:120'],
            'cp_fiscal' => ['nullable', 'string', 'max:20'],
            'telefono_fiscal' => ['nullable', 'string', 'max:50'],
            'telefono_celular_fiscal' => ['nullable', 'string', 'max:50'],

            // Campos adicionales propios del proveedor.
            'campos_personalizados' => ['nullable', 'array'],
            'campos_personalizados.*.nombre' => ['nullable', 'string', 'max:100'],
            'campos_personalizados.*.tipo' => ['nullable', 'in:texto,numerico,fecha,opciones'],
            'campos_personalizados.*.opciones' => ['nullable', 'array'],
            'campos_personalizados.*.opciones.*' => ['nullable', 'string', 'max:100'],
            'campos_personalizados.*.valor' => ['nullable', 'string', 'max:500'],

            // Personas de contacto (0..N)
            'contactos' => ['nullable', 'array'],
            'contactos.*.nombre' => ['nullable', 'string', 'max:255'],
            'contactos.*.apellido' => ['nullable', 'string', 'max:255'],
            'contactos.*.telefono' => ['nullable', 'string', 'max:50'],
            'contactos.*.telefono_celular' => ['nullable', 'string', 'max:50'],
            'contactos.*.email' => ['nullable', 'email', 'max:255'],
            'contactos.*.enviar_mails' => ['nullable', 'boolean'],
        ];
    }

    protected function normalizarCuit(): void
    {
        if ($this->has('cuit')) {
            $cuit = preg_replace('/\D/', '', (string) $this->input('cuit'));
            $this->merge(['cuit' => $cuit === '' ? null : $cuit]);
        }
    }
}
