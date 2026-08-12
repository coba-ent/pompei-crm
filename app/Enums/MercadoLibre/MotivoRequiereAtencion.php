<?php

namespace App\Enums\MercadoLibre;

/** Motivo concreto por el que una orden queda en "Requiere atención" (FR-007b, FR-052). */
enum MotivoRequiereAtencion: string
{
    case PublicacionSinVincular = 'publicacion_sin_vincular';
    case PublicacionConVariantes = 'publicacion_con_variantes';
    case ClienteAmbiguo = 'cliente_ambiguo';
    case ProductoInexistente = 'producto_inexistente';
    case MonedaInvalida = 'moneda_invalida';
    case AlertaFraude = 'alerta_fraude';
    case DatosIncompletos = 'datos_incompletos';
    case ErrorConversion = 'error_conversion';
    case OrdenCancelada = 'orden_cancelada';
    case OrdenReembolsoParcial = 'orden_reembolso_parcial';
    case OrdenEnMediacion = 'orden_en_mediacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PublicacionSinVincular => 'Publicación sin vincular a un producto',
            self::PublicacionConVariantes => 'Publicación con variantes (no soportado)',
            self::ClienteAmbiguo => 'Más de un Cliente con el mismo apodo de Mercado Libre',
            self::ProductoInexistente => 'El producto vinculado fue eliminado o inactivado',
            self::MonedaInvalida => 'Moneda distinta a la del negocio',
            self::AlertaFraude => 'Mercado Libre marcó la orden con alerta de fraude — no despachar',
            self::DatosIncompletos => 'Faltan datos del comprador en la respuesta de Mercado Libre',
            self::ErrorConversion => 'Falla inesperada durante la conversión',
            self::OrdenCancelada => 'La orden fue cancelada en Mercado Libre después de facturarse',
            self::OrdenReembolsoParcial => 'Mercado Libre informó un reembolso parcial',
            self::OrdenEnMediacion => 'Hay un reclamo en mediación; el desenlace todavía no está definido',
        };
    }

    /** Motivos que corresponden a una cancelación posterior a la conversión (spec 063). */
    public static function motivosDeCancelacionPosterior(): array
    {
        return [self::OrdenCancelada, self::OrdenReembolsoParcial, self::OrdenEnMediacion];
    }
}
