<?php

namespace App\Services\MercadoLibre;

use App\Models\Integraciones\MercadoLibrePublicacionProducto as Vinculo;

/**
 * Campañas de cuotas de Mercado Libre Argentina (spec 115, ajuste 16/10/2026).
 *
 * En MLA las cuotas de una publicación no son un campo propio: viajan como un **tag** en `/items`
 * y sólo puede haber una campaña por publicación. Cada tipo admite sus propias campañas:
 *
 *   gold_pro      sin tag = 6 cuotas al mismo precio (lo que ML pone solo)  · 3x / 9x / 12x_campaign
 *   gold_special  sin tag = sin cuotas (sólo las de los bancos)              · pcj-co-funded
 *
 * "Sin tag" se representa con `no-campaign`, el mismo id que usa `/special_installments/campaigns`.
 * Fuente: https://developers.mercadolibre.com.ar/es_ar/campana-con-cuotas-para-marketplace
 */
class CampaniasCuotas
{
    public const SIN_CAMPANIA = 'no-campaign';

    /** Opciones conocidas por tipo, en el orden en que se muestran. */
    private const CONOCIDAS = [
        Vinculo::TIPO_PREMIUM => [
            '3x_campaign' => '3 cuotas sin interés',
            self::SIN_CAMPANIA => '6 cuotas sin interés',
            '9x_campaign' => '9 cuotas sin interés',
            '12x_campaign' => '12 cuotas sin interés',
        ],
        Vinculo::TIPO_CLASICA => [
            self::SIN_CAMPANIA => 'Sin cuotas (sólo las de los bancos)',
            'pcj-co-funded' => '3 a 12 cuotas con interés bajo',
        ],
    ];

    /** Lista documentada para el tipo: se usa cuando ML no responde qué campañas admite la categoría. */
    public static function opcionesPorDefecto(string $tipo): array
    {
        return collect(self::CONOCIDAS[$tipo] ?? [])
            ->map(fn ($etiqueta, $id) => ['id' => $id, 'etiqueta' => $etiqueta])
            ->values()
            ->all();
    }

    /**
     * Opciones a partir de la respuesta de `GET /special_installments/campaigns`. Una campaña nueva
     * que ML active y el CRM no conozca se ofrece igual, con la descripción de ML. "Sin tag" se
     * agrega siempre: es lo que ML aplica por defecto y nunca está vedado.
     */
    public static function opcionesDesdeMl(array $respuesta, string $tipo): ?array
    {
        $canales = array_is_list($respuesta) ? $respuesta : [$respuesta];
        $canal = collect($canales)->first(fn ($c) => is_array($c) && ($c['channel'] ?? 'marketplace') === 'marketplace');
        $porTipo = collect($canal['available_campaigns'] ?? [])->firstWhere('listing_type_id', $tipo);

        if (! is_array($porTipo) || ! is_array($porTipo['available_campaigns'] ?? null)) {
            return null;
        }

        $ids = collect($porTipo['available_campaigns'])
            ->filter(fn ($c) => is_array($c) && ! empty($c['campaign_id']))
            ->mapWithKeys(fn ($c) => [$c['campaign_id'] => $c['description_campaign'] ?? $c['campaign_id']]);

        $conocidas = self::CONOCIDAS[$tipo] ?? [];
        $opciones = [];

        foreach ($conocidas as $id => $etiqueta) {
            if ($id === self::SIN_CAMPANIA || $ids->has($id)) {
                $opciones[] = ['id' => $id, 'etiqueta' => $etiqueta];
            }
        }

        foreach ($ids as $id => $descripcion) {
            if (! array_key_exists($id, $conocidas) && ! self::esOtroTipo($id, $tipo)) {
                $opciones[] = ['id' => $id, 'etiqueta' => $descripcion];
            }
        }

        return $opciones;
    }

    /** Campaña vigente según los tags del ítem (`no-campaign` si no tiene ninguna). */
    public static function vigente(array $tags): string
    {
        foreach ($tags as $tag) {
            if (self::esTagDeCampania($tag)) {
                return $tag;
            }
        }

        return self::SIN_CAMPANIA;
    }

    public static function etiqueta(string $campania, ?string $tipo): string
    {
        if ($tipo && isset(self::CONOCIDAS[$tipo][$campania])) {
            return self::CONOCIDAS[$tipo][$campania];
        }

        foreach (self::CONOCIDAS as $opciones) {
            if ($campania !== self::SIN_CAMPANIA && isset($opciones[$campania])) {
                return $opciones[$campania];
            }
        }

        return $campania === self::SIN_CAMPANIA ? 'Sin campaña de cuotas' : $campania;
    }

    /**
     * Tags a enviar en el `PUT /items` para dejar la publicación con `$campania`. ML reemplaza la
     * lista entera: se reenvían todos los demás tags y se quita cualquier otra campaña.
     */
    public static function tagsCon(array $tags, string $campania): array
    {
        $resto = array_values(array_filter($tags, fn ($t) => is_string($t) && ! self::esTagDeCampania($t)));

        return $campania === self::SIN_CAMPANIA ? $resto : [$campania, ...$resto];
    }

    private static function esTagDeCampania(string $tag): bool
    {
        return $tag === 'pcj-co-funded' || str_ends_with($tag, '_campaign');
    }

    /** Una campaña conocida de otro tipo no se ofrece aunque ML la liste en este. */
    private static function esOtroTipo(string $id, string $tipo): bool
    {
        foreach (self::CONOCIDAS as $otroTipo => $opciones) {
            if ($otroTipo !== $tipo && $id !== self::SIN_CAMPANIA && isset($opciones[$id])) {
                return true;
            }
        }

        return false;
    }
}
