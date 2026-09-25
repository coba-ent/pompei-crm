<?php

namespace Tests\Feature\Tesoreria;

use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 111 — cambiar la caja de un movimiento nativo desde Editar Movimiento.
 *
 * Antes el modal sólo tenía Fecha, Monto y Observación: corregir una imputación obligaba a borrar
 * el movimiento y rehacerlo, perdiendo la trazabilidad del asiento.
 *
 * El caso delicado son las **transferencias**, que son dos asientos unidos por `transferencia_id`.
 * Ahí se editan las dos cajas —origen y destino son datos distintos— y nunca pueden terminar siendo
 * la misma. El docblock de `updateMovimiento()` documenta un incidente real de $105.449,74 por
 * editar una sola pata, así que la atomicidad y la invariante de suma total son lo que más importa.
 */
class ReimputarMovimientoTest extends TestCase
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

    private function movimiento(CuentaTesoreria $cuenta, float $monto, array $extra = []): MovimientoTesoreria
    {
        return MovimientoTesoreria::create(array_merge([
            'cuenta_tesoreria_id' => $cuenta->id,
            'tipo' => 'movimiento_entre_cuentas',
            'fecha' => '2026-09-01',
            'monto' => $monto,
        ], $extra));
    }

    /** Suma de todos los movimientos vivos: la invariante que no puede cambiar al reimputar. */
    private function sumaTotal(): float
    {
        return round((float) MovimientoTesoreria::sum('monto'), 2);
    }

    private function editar(User $user, MovimientoTesoreria $mov, array $datos)
    {
        return $this->actingAs($user)->putJson(route('tesoreria.movimientos.update', $mov), array_merge([
            'fecha' => $mov->fecha instanceof \DateTimeInterface ? $mov->fecha->format('Y-m-d') : $mov->fecha,
            'monto' => (float) $mov->monto,
        ], $datos));
    }

    // ------------------------------------------------------------------ US1: movimiento suelto

    public function test_cambiar_la_caja_de_un_movimiento_suelto(): void
    {
        $user = $this->usuario();
        $vieja = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        $nueva = CuentaTesoreria::factory()->tipo('banco')->create(['saldo_inicial' => 0]);
        $mov = $this->movimiento($vieja, 50000);

        $this->editar($user, $mov, ['cuenta_tesoreria_id' => $nueva->id])->assertOk();

        $this->assertSame($nueva->id, $mov->fresh()->cuenta_tesoreria_id);
        $this->assertSame(0.0, $vieja->fresh()->saldoA(), 'La caja vieja tiene que quedar sin el importe.');
        $this->assertSame(50000.0, $nueva->fresh()->saldoA(), 'La caja nueva tiene que recibirlo.');
    }

    /** FR-014 / SC-003: reimputar mueve plata entre cajas, no la crea ni la destruye. */
    public function test_reimputar_no_altera_la_suma_total_de_tesoreria(): void
    {
        $user = $this->usuario();
        $vieja = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        $nueva = CuentaTesoreria::factory()->tipo('banco')->create(['saldo_inicial' => 0]);
        $mov = $this->movimiento($vieja, 50000);

        $antes = $this->sumaTotal();
        $this->editar($user, $mov, ['cuenta_tesoreria_id' => $nueva->id])->assertOk();

        $this->assertSame($antes, $this->sumaTotal());
    }

    /** FR-008: un request sin la caja (cliente viejo) no puede moverla. */
    public function test_editar_sin_mandar_la_caja_no_la_cambia(): void
    {
        $user = $this->usuario();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        $mov = $this->movimiento($cuenta, 50000);

        $this->editar($user, $mov, ['monto' => 70000, 'observacion' => 'corregido'])->assertOk();

        $mov->refresh();
        $this->assertSame($cuenta->id, $mov->cuenta_tesoreria_id);
        $this->assertSame(70000.0, (float) $mov->monto);
        $this->assertSame('corregido', $mov->observacion);
    }

    /** FR-009: los movimientos con origen documental no se editan desde Tesorería. */
    public function test_un_movimiento_no_nativo_sigue_rechazandose(): void
    {
        $user = $this->usuario();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        $otra = CuentaTesoreria::factory()->tipo('banco')->create(['saldo_inicial' => 0]);
        $mov = $this->movimiento($cuenta, 50000, ['tipo' => 'cobro']);

        $this->editar($user, $mov, ['cuenta_tesoreria_id' => $otra->id])->assertStatus(422);

        $this->assertSame($cuenta->id, $mov->fresh()->cuenta_tesoreria_id);
    }

    // ------------------------------------------------------------------ US2: transferencias

    /** @return array{0: MovimientoTesoreria, 1: MovimientoTesoreria} salida (−) y entrada (+) */
    private function transferencia(CuentaTesoreria $origen, CuentaTesoreria $destino, float $monto): array
    {
        $tid = (string) Str::uuid();

        return [
            $this->movimiento($origen, -$monto, ['transferencia_id' => $tid]),
            $this->movimiento($destino, $monto, ['transferencia_id' => $tid]),
        ];
    }

    public function test_cambiar_solo_el_origen_deja_el_destino_intacto(): void
    {
        $user = $this->usuario();
        $origen = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        $destino = CuentaTesoreria::factory()->tipo('banco')->create(['saldo_inicial' => 0]);
        $nuevoOrigen = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        [$salida, $entrada] = $this->transferencia($origen, $destino, 50000);

        $this->editar($user, $salida, [
            'cuenta_tesoreria_id' => $nuevoOrigen->id,
            'cuenta_contraparte_id' => $destino->id,
        ])->assertOk();

        $this->assertSame($nuevoOrigen->id, $salida->fresh()->cuenta_tesoreria_id);
        $this->assertSame($destino->id, $entrada->fresh()->cuenta_tesoreria_id, 'El destino no se tocó.');
        $this->assertSame(0.0, $origen->fresh()->saldoA());
        $this->assertSame(-50000.0, $nuevoOrigen->fresh()->saldoA());
        $this->assertSame(50000.0, $destino->fresh()->saldoA());
    }

    public function test_cambiar_las_dos_cajas_en_la_misma_edicion(): void
    {
        $user = $this->usuario();
        $origen = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        $destino = CuentaTesoreria::factory()->tipo('banco')->create(['saldo_inicial' => 0]);
        $o2 = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        $d2 = CuentaTesoreria::factory()->tipo('banco')->create(['saldo_inicial' => 0]);
        [$salida, $entrada] = $this->transferencia($origen, $destino, 50000);

        $this->editar($user, $salida, [
            'cuenta_tesoreria_id' => $o2->id,
            'cuenta_contraparte_id' => $d2->id,
        ])->assertOk();

        $this->assertSame($o2->id, $salida->fresh()->cuenta_tesoreria_id);
        $this->assertSame($d2->id, $entrada->fresh()->cuenta_tesoreria_id);
        $this->assertSame(-50000.0, $o2->fresh()->saldoA());
        $this->assertSame(50000.0, $d2->fresh()->saldoA());
    }

    /** FR-004: una transferencia de una caja a sí misma no existe. */
    public function test_rechaza_que_origen_y_destino_sean_la_misma_caja(): void
    {
        $user = $this->usuario();
        $origen = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        $destino = CuentaTesoreria::factory()->tipo('banco')->create(['saldo_inicial' => 0]);
        [$salida, $entrada] = $this->transferencia($origen, $destino, 50000);

        $this->editar($user, $salida, [
            'cuenta_tesoreria_id' => $destino->id,
            'cuenta_contraparte_id' => $destino->id,
        ])->assertStatus(422)->assertJsonValidationErrors('cuenta_contraparte_id');

        // Nada se movió.
        $this->assertSame($origen->id, $salida->fresh()->cuenta_tesoreria_id);
        $this->assertSame($destino->id, $entrada->fresh()->cuenta_tesoreria_id);
    }

    /** SC-003 sobre una transferencia: mover las dos patas no crea ni destruye plata. */
    public function test_reimputar_una_transferencia_no_altera_la_suma_total(): void
    {
        $user = $this->usuario();
        $origen = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        $destino = CuentaTesoreria::factory()->tipo('banco')->create(['saldo_inicial' => 0]);
        $o2 = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        [$salida] = $this->transferencia($origen, $destino, 50000);

        $antes = $this->sumaTotal();
        $this->editar($user, $salida, [
            'cuenta_tesoreria_id' => $o2->id,
            'cuenta_contraparte_id' => $destino->id,
        ])->assertOk();

        $this->assertSame($antes, $this->sumaTotal());
    }

    /** FR-008: el ajuste de monto/fecha en la contraparte que ya existía sigue funcionando. */
    public function test_editar_el_monto_sigue_ajustando_la_contraparte(): void
    {
        $user = $this->usuario();
        $origen = CuentaTesoreria::factory()->tipo('efectivo')->create(['saldo_inicial' => 0]);
        $destino = CuentaTesoreria::factory()->tipo('banco')->create(['saldo_inicial' => 0]);
        [$salida, $entrada] = $this->transferencia($origen, $destino, 50000);

        $this->editar($user, $salida, ['monto' => -70000, 'fecha' => '2026-09-15'])->assertOk();

        $this->assertSame(70000.0, (float) $entrada->fresh()->monto, 'La contraparte sigue el monto opuesto.');
        $this->assertSame('2026-09-15', $entrada->fresh()->fecha->format('Y-m-d'));
        $this->assertSame(0.0, $this->sumaTotal(), 'Las dos patas se compensan.');
    }
}
