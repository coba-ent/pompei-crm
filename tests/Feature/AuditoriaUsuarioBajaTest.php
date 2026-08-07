<?php

namespace Tests\Feature;

use App\Models\LogAuditoria;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Edge case de spec.md: el nombre de usuario se preserva aunque el usuario se dé de baja (FR-008). */
class AuditoriaUsuarioBajaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_usuario_nombre_se_preserva_tras_dar_de_baja_al_usuario(): void
    {
        $usuario = auth()->user();
        Venta::factory()->create();

        $log = LogAuditoria::latest('id')->first();
        $this->assertSame($usuario->name, $log->usuario_nombre);

        $usuario->update(['activo' => false]);

        $log->refresh();
        $this->assertSame($usuario->name, $log->usuario_nombre);
        $this->assertSame($usuario->id, $log->usuario_id);
    }
}
