<?php

namespace Tests\Feature;

use App\Models\ListaPrecio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListaPrecioTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_renombra_y_elimina_lista(): void
    {
        $this->postJson(route('listas-precio.store'), ['nombre' => 'Mayorista'])
            ->assertOk()->assertJsonPath('ok', true);

        $lista = ListaPrecio::where('nombre', 'Mayorista')->firstOrFail();

        $this->patchJson(route('listas-precio.update', $lista), ['nombre' => 'Mayorista/Obras'])
            ->assertOk();
        $this->assertSame('Mayorista/Obras', $lista->fresh()->nombre);

        $this->deleteJson(route('listas-precio.destroy', $lista))->assertOk();
        $this->assertDatabaseMissing('listas_precio', ['id' => $lista->id]);
    }

    public function test_rechaza_nombre_duplicado(): void
    {
        ListaPrecio::create(['nombre' => 'Minorista', 'activo' => true]);

        $this->postJson(route('listas-precio.store'), ['nombre' => 'Minorista'])
            ->assertStatus(422);
    }
}
