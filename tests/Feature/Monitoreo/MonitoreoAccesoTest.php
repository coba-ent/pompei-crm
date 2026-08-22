<?php

namespace Tests\Feature\Monitoreo;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec 073 — sin `monitoreo.ver`, 403 en pantalla y en `resumen`; con `ver` y sin `gestionar`,
 * 403 en cada escritura; con `ver`, se puede marcar como leído.
 */
class MonitoreoAccesoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    private function usuarioCon(array $codigos): User
    {
        $rol = Rol::create(['nombre' => 'Rol de prueba '.uniqid(), 'es_sistema' => false]);
        foreach ($codigos as $codigo) {
            $permiso = Permiso::firstOrCreate(['codigo' => $codigo], ['descripcion' => $codigo, 'modulo' => 'monitoreo']);
            $rol->permisos()->attach($permiso->id);
        }
        $user = User::factory()->create();
        $user->roles()->attach($rol->id);

        return $user;
    }

    public function test_sin_permiso_recibe_403_en_index(): void
    {
        $user = $this->usuarioCon([]);

        $this->actingAs($user)->get(route('monitoreo.index'))->assertForbidden();
    }

    public function test_sin_permiso_recibe_403_en_resumen(): void
    {
        $user = $this->usuarioCon([]);

        $this->actingAs($user)->getJson(route('monitoreo.resumen'))->assertForbidden();
    }

    public function test_con_ver_y_sin_gestionar_recibe_403_en_cada_escritura(): void
    {
        $user = $this->usuarioCon(['monitoreo.ver']);

        $this->actingAs($user)->postJson(route('monitoreo.destrabar'), ['ml_item_id' => 'MLA1'])->assertForbidden();
        $this->actingAs($user)->postJson(route('monitoreo.reactivar'), ['ml_item_id' => 'MLA1'])->assertForbidden();
        $this->actingAs($user)->postJson(route('monitoreo.sincronizar'), ['que' => 'stock'])->assertForbidden();
        $this->actingAs($user)->postJson(route('monitoreo.puntoReposicion'), ['producto_id' => 1])->assertForbidden();
    }

    public function test_con_ver_puede_marcar_leido(): void
    {
        $user = $this->usuarioCon(['monitoreo.ver']);

        $this->actingAs($user)
            ->postJson(route('monitoreo.notificaciones.leer'), ['claves' => ['reposicion:1']])
            ->assertOk()
            ->assertJsonStructure(['ok', 'sinLeer']);
    }
}
