<?php

namespace Tests\Feature\Tesoreria;

use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Editar una cuenta de tesorería cambia SÓLO su nombre y su visibilidad.
 *
 * El caso que motivó estos tests es real y estaba en producción: la columna
 * `cuentas_tesoreria.saldo_inicial` quedó desincronizada del movimiento de Saldo
 * Inicial en la migración desde Contagram (la columna dice 0,00 en cuentas cuyo
 * movimiento vale -1.000.000). Como el formulario mandaba esa columna y el
 * controlador reescribía el movimiento con lo recibido, entrar a una de esas
 * cuentas sólo para renombrarla y apretar Guardar le borraba el saldo inicial.
 */
class EditarCuentaNoTocaSaldoInicialTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        $rol = Rol::create(['nombre' => 'Tesorero '.uniqid(), 'es_sistema' => false]);

        foreach (['tesoreria.ver', 'tesoreria.editar'] as $codigo) {
            $permiso = Permiso::firstOrCreate(
                ['codigo' => $codigo],
                ['descripcion' => 'Test', 'modulo' => 'tesoreria'],
            );
            $rol->permisos()->attach($permiso->id);
        }

        $user = User::factory()->create();
        $user->roles()->attach($rol->id);

        return $user;
    }

    /**
     * Reproduce el escenario de producción: columna en 0 pero movimiento real en
     * -1.000.000. Renombrar no puede tocar ese millón.
     */
    public function test_renombrar_no_altera_el_movimiento_de_saldo_inicial_desincronizado(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create([
            'nombre' => 'Mercado Pago',
            'saldo_inicial' => 0,
            'saldo_inicial_fecha' => null,
        ]);

        $movimiento = MovimientoTesoreria::create([
            'cuenta_tesoreria_id' => $cuenta->id,
            'tipo' => 'saldo_inicial',
            'monto' => -1000000,
            'fecha' => Carbon::parse('2022-02-08'),
        ]);

        $this->actingAs($this->usuario())
            ->putJson(route('tesoreria.cuentas.update', $cuenta), [
                'nombre' => 'Mercado Pago ARS',
                'visible' => 1,
            ])
            ->assertOk();

        $this->assertSame('Mercado Pago ARS', $cuenta->fresh()->nombre);
        $this->assertSame(-1000000.0, (float) $movimiento->fresh()->monto);
        $this->assertSame('2022-02-08', $movimiento->fresh()->fecha->format('Y-m-d'));
        $this->assertSame(-1000000.0, $cuenta->fresh()->saldoA());
    }

    /** La fecha en NULL ya no bloquea la edición: no se pide más (era el bug del modal). */
    public function test_se_puede_renombrar_una_cuenta_con_fecha_de_saldo_inicial_nula(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('a_cobrar')->create([
            'nombre' => 'Cabal Acreditaciones a Cobrar',
            'saldo_inicial_fecha' => null,
        ]);

        $this->actingAs($this->usuario())
            ->putJson(route('tesoreria.cuentas.update', $cuenta), ['nombre' => 'Cabal Acreditaciones'])
            ->assertOk();

        $this->assertSame('Cabal Acreditaciones', $cuenta->fresh()->nombre);
    }

    /** Aunque alguien arme el request a mano, el backend ignora esos campos. */
    public function test_mandar_saldo_inicial_y_fecha_a_mano_no_tiene_efecto(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create([
            'nombre' => 'Caja del Local',
            'saldo_inicial' => 5000,
            'saldo_inicial_fecha' => Carbon::parse('2021-04-02'),
        ]);

        $movimiento = MovimientoTesoreria::create([
            'cuenta_tesoreria_id' => $cuenta->id,
            'tipo' => 'saldo_inicial',
            'monto' => 5000,
            'fecha' => Carbon::parse('2021-04-02'),
        ]);

        $this->actingAs($this->usuario())
            ->putJson(route('tesoreria.cuentas.update', $cuenta), [
                'nombre' => 'Caja del Local',
                'saldo_inicial' => 999999,
                'saldo_inicial_fecha' => '2026-01-01',
            ])
            ->assertOk();

        $fresca = $cuenta->fresh();
        $this->assertSame(5000.0, (float) $fresca->saldo_inicial);
        $this->assertSame('2021-04-02', $fresca->saldo_inicial_fecha->format('Y-m-d'));
        $this->assertSame(5000.0, (float) $movimiento->fresh()->monto);
        $this->assertSame('2021-04-02', $movimiento->fresh()->fecha->format('Y-m-d'));
    }

    /** El tipo sigue siendo inmutable en la edición. */
    public function test_el_tipo_no_cambia_aunque_se_lo_mande(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create(['nombre' => 'Caja chica gastos']);

        $this->actingAs($this->usuario())
            ->putJson(route('tesoreria.cuentas.update', $cuenta), [
                'nombre' => 'Caja chica gastos',
                'tipo' => 'banco',
            ])
            ->assertOk();

        $this->assertSame('efectivo', $cuenta->fresh()->tipo);
    }

    /** La visibilidad sí se sigue pudiendo cambiar. */
    public function test_la_visibilidad_se_puede_cambiar(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create(['nombre' => 'USD Online', 'visible' => true]);

        $this->actingAs($this->usuario())
            ->putJson(route('tesoreria.cuentas.update', $cuenta), ['nombre' => 'USD Online', 'visible' => 0])
            ->assertOk();

        $this->assertFalse($cuenta->fresh()->visible);
    }
}
