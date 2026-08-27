<?php

namespace Tests\Feature\Integraciones;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\MercadoLibreRetencionPrecio;
use App\Models\Producto;
use App\Services\MercadoLibre\EvaluadorCambioPrecio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 084 T006–T009 — las reglas del corte, una por una.
 *
 * `EvaluadorCambioPrecio` no escribe ni llama a la API, así que acá se puede ser exhaustivo sin
 * montar nada. Es la mitad del sistema que decide si se publica o no: si algo de esto está mal, se
 * publica barato.
 */
class EvaluadorCambioPrecioTest extends TestCase
{
    use RefreshDatabase;

    private function vinculo(?float $precioPublicado): MercadoLibrePublicacionProducto
    {
        return MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA'.fake()->unique()->numberBetween(1000, 999999),
            'producto_id' => Producto::factory()->create()->id,
            'precio_publicado' => $precioPublicado,
        ]);
    }

    private function evaluar(?float $publicado, float $propuesto, float $umbral = 20.0): array
    {
        MercadoLibreConfiguracion::actual()->update([
            'umbral_caida_precio_pct' => $umbral,
            'corte_precios_activo' => true,
        ]);

        return app(EvaluadorCambioPrecio::class)->evaluar(
            $this->vinculo($publicado),
            $propuesto,
            MercadoLibreConfiguracion::actual()->fresh(),
        );
    }

    /**
     * La tabla del quickstart §Caso 3. La fila que importa es la del umbral exacto: es la que se
     * olvida al implementar y la que define el borde.
     */
    public function test_el_umbral_define_el_borde_y_el_valor_exacto_pasa(): void
    {
        $this->assertTrue($this->evaluar(100_000, 130_000)['publicar'], 'Una subida del 30% se publica.');
        $this->assertTrue($this->evaluar(100_000, 100_000)['publicar'], 'Sin cambio se publica.');
        $this->assertTrue($this->evaluar(100_000, 85_000)['publicar'], 'Una caída del 15% está dentro del umbral.');
        $this->assertTrue($this->evaluar(100_000, 80_000)['publicar'], 'Una caída de exactamente 20% PASA: se retiene lo mayor al umbral.');
        $this->assertFalse($this->evaluar(100_000, 79_900)['publicar'], 'Una caída del 20,1% se retiene.');
    }

    public function test_una_subida_no_se_retiene_nunca_por_grande_que_sea(): void
    {
        $r = $this->evaluar(100, 100_000_000);

        $this->assertTrue($r['publicar'], 'El corte es sólo para bajadas: una subida de escala se publica (Decisión 6).');
    }

    public function test_precio_cero_o_negativo_se_retiene_aunque_el_umbral_sea_100(): void
    {
        foreach ([0.0, -1.0, -50_000.0] as $precio) {
            $r = $this->evaluar(100_000, $precio, umbral: 100);

            $this->assertFalse($r['publicar']);
            $this->assertSame(MercadoLibreRetencionPrecio::MOTIVO_PRECIO_INVALIDO, $r['motivo']);
        }
    }

    /**
     * El caso contraintuitivo, y el que más fácil se implementa al revés: sin referencia la lectura
     * natural es "no supera el umbral, entonces publicá". Es la peligrosa.
     */
    public function test_sin_precio_publicado_conocido_se_retiene_incluso_si_el_precio_sube(): void
    {
        $r = $this->evaluar(null, 500_000);

        $this->assertFalse($r['publicar'], 'Sin saber qué hay publicado no se puede afirmar que no se está bajando.');
        $this->assertSame(MercadoLibreRetencionPrecio::MOTIVO_SIN_REFERENCIA, $r['motivo']);
    }

    public function test_umbral_cero_retiene_cualquier_bajada_por_minima_que_sea(): void
    {
        $this->assertFalse($this->evaluar(100_000, 99_999, umbral: 0)['publicar']);
        $this->assertTrue($this->evaluar(100_000, 100_000, umbral: 0)['publicar'], 'Sin cambio no es una bajada.');
    }

    /** Umbral 100 no es un interruptor de apagado: las otras dos guardas siguen valiendo. */
    public function test_umbral_cien_no_apaga_el_corte(): void
    {
        $this->assertTrue($this->evaluar(100_000, 1, umbral: 100)['publicar'], 'Por porcentaje ya no retiene nada.');
        $this->assertFalse($this->evaluar(100_000, 0, umbral: 100)['publicar'], 'Pero el precio inválido sigue reteniendo.');
        $this->assertFalse($this->evaluar(null, 100_000, umbral: 100)['publicar'], 'Y el sin referencia también.');
    }

    /** Los dos incidentes reales que motivaron la spec, con sus números. */
    public function test_los_dos_incidentes_de_agosto_quedan_retenidos(): void
    {
        $premium = $this->evaluar(317_743.34, 218_607.42);
        $this->assertFalse($premium['publicar'], 'Incidente del 25/08: la Premium al precio Clásico.');
        $this->assertEqualsWithDelta(31.20, $premium['caida_pct'], 0.01);

        $escala = $this->evaluar(262_252.00, 262.26);
        $this->assertFalse($escala['publicar'], 'Incidente del 06/08: el precio dividido por 1000.');
        $this->assertEqualsWithDelta(99.90, $escala['caida_pct'], 0.01);
    }
}
