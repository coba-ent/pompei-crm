<?php

namespace App\Enums\Tiendanube;

/**
 * Motivo concreto por el que una orden de Tiendanube queda en "Requiere
 * atención" (FR-007b, FR-052). Sin `PublicacionConVariantes` (toda variante de
 * Tiendanube es vinculable) ni `AlertaFraude` (sin equivalente en la API de
 * Tiendanube, spec.md Assumptions) — motivos propios, no reutilizados de
 * `App\Enums\MercadoLibre\MotivoRequiereAtencion` (research.md R6).
 */
enum MotivoRequiereAtencion: string
{
    case VarianteSinVincular = 'variante_sin_vincular';
    case ClienteAmbiguo = 'cliente_ambiguo';
    case ProductoInexistente = 'producto_inexistente';
    case MonedaInvalida = 'moneda_invalida';
    case CuentaTesoreriaNoConfigurada = 'cuenta_tesoreria_no_configurada';
    case DatosIncompletos = 'datos_incompletos';
    case ErrorConversion = 'error_conversion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VarianteSinVincular => 'Variante sin vincular a un producto',
            self::ClienteAmbiguo => 'Más de un Cliente con el mismo email',
            self::ProductoInexistente => 'El producto vinculado fue eliminado o inactivado',
            self::MonedaInvalida => 'Moneda distinta a la del negocio',
            self::CuentaTesoreriaNoConfigurada => 'No hay una cuenta de Tesorería configurada para Tiendanube',
            self::DatosIncompletos => 'Faltan datos de la orden informados por Tiendanube',
            self::ErrorConversion => 'Falla inesperada durante la conversión',
        };
    }
}
