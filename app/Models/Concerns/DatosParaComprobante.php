<?php

namespace App\Models\Concerns;

/**
 * Datos de contacto listos para imprimir en un comprobante.
 *
 * Clientes y proveedores guardan el domicilio y el teléfono por duplicado: una
 * versión comercial y una fiscal. En la base real los datos quedaron casi todos
 * del lado fiscal —2.980 clientes con domicilio fiscal contra 328 con el
 * comercial—, así que leer un solo campo hacía que la factura saliera con un
 * guion aunque el dato estuviera cargado. Estos métodos recorren las variantes
 * en orden de preferencia y devuelven la primera con contenido real.
 */
trait DatosParaComprobante
{
    public function domicilioParaComprobante(): ?string
    {
        return $this->primerValorNoVacio([
            $this->domicilio ?? null,
            $this->domicilio_fiscal ?? null,
        ]);
    }

    public function telefonoParaComprobante(): ?string
    {
        return $this->primerValorNoVacio([
            $this->telefono ?? null,
            $this->telefono_celular ?? null,
            $this->telefono_fiscal ?? null,
            $this->telefono_celular_fiscal ?? null,
        ]);
    }

    /**
     * Un campo que quedó en cadena vacía o en espacios equivale a no cargado.
     */
    private function primerValorNoVacio(array $valores): ?string
    {
        foreach ($valores as $valor) {
            $valor = trim((string) $valor);

            if ($valor !== '') {
                return $valor;
            }
        }

        return null;
    }
}
