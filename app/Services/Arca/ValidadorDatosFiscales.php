<?php

namespace App\Services\Arca;

/** Valida los datos fiscales mínimos requeridos según Tipo de Comprobante (FR-009). */
class ValidadorDatosFiscales
{
    /**
     * @param  array{cuit?: string|null, dni?: string|null}  $cliente
     * @return string|null Motivo de rechazo, o null si es válido.
     */
    public function validar(string $tipoComprobante, array $cliente): ?string
    {
        if ($tipoComprobante === 'A' && empty($cliente['cuit'])) {
            return 'El cliente debe tener CUIT cargado para emitir Factura A.';
        }

        if ($tipoComprobante === 'A' && ! $this->cuitValido($cliente['cuit'])) {
            return 'El CUIT del cliente no tiene un formato válido para emitir Factura A.';
        }

        return null;
    }

    private function cuitValido(string $cuit): bool
    {
        return (bool) preg_match('/^\d{11}$/', preg_replace('/\D/', '', $cuit));
    }
}
