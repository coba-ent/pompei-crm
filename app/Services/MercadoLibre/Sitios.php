<?php

namespace App\Services\MercadoLibre;

/**
 * Mapa `site_id → dominio de autorización` (research.md R2). La API
 * (api.mercadolibre.com) es única para todos los países, pero el dominio de
 * autorización es por sitio.
 */
class Sitios
{
    private const MAPA = [
        'MLA' => ['nombre' => 'Argentina', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.ar'],
        'MLB' => ['nombre' => 'Brasil', 'dominio_autorizacion' => 'https://auth.mercadolivre.com.br'],
        'MLM' => ['nombre' => 'México', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.mx'],
        'MLC' => ['nombre' => 'Chile', 'dominio_autorizacion' => 'https://auth.mercadolibre.cl'],
        'MCO' => ['nombre' => 'Colombia', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.co'],
        'MPE' => ['nombre' => 'Perú', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.pe'],
        'MLU' => ['nombre' => 'Uruguay', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.uy'],
        'MLV' => ['nombre' => 'Venezuela', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.ve'],
        'MEC' => ['nombre' => 'Ecuador', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.ec'],
        'MCR' => ['nombre' => 'Costa Rica', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.cr'],
        'MPA' => ['nombre' => 'Panamá', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.pa'],
        'MRD' => ['nombre' => 'República Dominicana', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.do'],
        'MGT' => ['nombre' => 'Guatemala', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.gt'],
        'MHN' => ['nombre' => 'Honduras', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.hn'],
        'MNI' => ['nombre' => 'Nicaragua', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.ni'],
        'MSV' => ['nombre' => 'El Salvador', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.sv'],
        'MBO' => ['nombre' => 'Bolivia', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.bo'],
        'MPY' => ['nombre' => 'Paraguay', 'dominio_autorizacion' => 'https://auth.mercadolibre.com.py'],
    ];

    public static function claves(): array
    {
        return array_keys(self::MAPA);
    }

    public static function nombre(string $siteId): ?string
    {
        return self::MAPA[$siteId]['nombre'] ?? null;
    }

    public static function dominioAutorizacion(string $siteId): ?string
    {
        return self::MAPA[$siteId]['dominio_autorizacion'] ?? null;
    }

    public static function paraSelect(): array
    {
        return collect(self::MAPA)->map(fn ($datos, $clave) => [
            'id' => $clave,
            'texto' => $clave.' — '.$datos['nombre'],
        ])->values()->all();
    }
}
