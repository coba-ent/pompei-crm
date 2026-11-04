<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** `stock:corregir-hora-movimientos`: pasa a hora argentina los movimientos grabados en UTC. */
class CorregirHoraMovimientosStockTest extends TestCase
{
    use RefreshDatabase;

    private ?Producto $producto = null;

    private ?Deposito $deposito = null;

    private function movimiento(string $fecha, string $creado, string $tipo = 'salida'): int
    {
        $producto = $this->producto ??= Producto::factory()->create(['tipo' => 'producto']);
        $deposito = $this->deposito ??= Deposito::create(['nombre' => 'Local']);

        return DB::table('movimientos_stock')->insertGetId([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => $tipo, 'cantidad' => -1, 'fecha' => $fecha,
            'created_at' => $creado, 'updated_at' => $creado,
        ]);
    }

    private function fecha(int $id): string
    {
        return (string) DB::table('movimientos_stock')->where('id', $id)->value('fecha');
    }

    public function test_corrige_utc_y_medianoche_del_mismo_dia_sin_tocar_el_resto(): void
    {
        // A: venta de ML grabada con now() en UTC (14:19 UTC = 11:19 AR).
        $utc = $this->movimiento('2026-11-02 14:19:03', '2026-11-02 14:19:03');
        // B: ajuste del modal con el día de hoy, cargado a las 10:05 AR (13:05 UTC).
        $medianoche = $this->movimiento('2026-11-02 00:00:00', '2026-11-02 13:05:00', 'ajuste');
        // Compra con fecha retroactiva: la hora real no existe, se deja.
        $retroactiva = $this->movimiento('2026-10-28 00:00:00', '2026-11-02 13:05:00', 'entrada');
        // Histórico importado de Contagram: ya está en hora argentina.
        $historico = $this->movimiento('2025-06-15 16:40:00', '2026-08-20 12:00:00');
        // Grabado ya con la regla nueva (hora AR = created_at − 3 h).
        $nuevo = $this->movimiento('2026-11-04 12:00:00', '2026-11-04 15:00:00');

        $this->artisan('stock:corregir-hora-movimientos')->assertSuccessful();
        $this->assertSame('2026-11-02 14:19:03', $this->fecha($utc), 'Sin --aplicar no escribe.');

        $this->artisan('stock:corregir-hora-movimientos', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame('2026-11-02 11:19:03', $this->fecha($utc));
        $this->assertSame('2026-11-02 10:05:00', $this->fecha($medianoche));
        $this->assertSame('2026-10-28 00:00:00', $this->fecha($retroactiva));
        $this->assertSame('2025-06-15 16:40:00', $this->fecha($historico));
        $this->assertSame('2026-11-04 12:00:00', $this->fecha($nuevo));

        // Idempotente: una segunda corrida no vuelve a restar 3 horas.
        $this->artisan('stock:corregir-hora-movimientos', ['--aplicar' => true])->assertSuccessful();
        $this->assertSame('2026-11-02 11:19:03', $this->fecha($utc));
        $this->assertSame('2026-11-02 10:05:00', $this->fecha($medianoche));
    }
}
