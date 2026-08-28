<?php

namespace Tests\Feature\Informes;

use App\Models\InformeVista;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec 069 — vistas guardadas de "Arma tu Informe".
 *
 * Es el único punto de ESCRITURA del módulo Informes, así que se cubre entero: alta, listado,
 * borrado, validaciones y aislamiento entre informes.
 */
class InformesVistasTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    /** @return array<string, mixed> */
    private function configValida(array $reemplazos = []): array
    {
        return array_merge([
            'filas' => ['clientes'],
            'columnas' => ['fecha_emision.anio', 'fecha_emision.mes'],
            'dato' => 'total_venta',
            'accion' => 'suma',
            'exclusiones' => [],
        ], $reemplazos);
    }

    public function test_guardar_una_vista_la_deja_disponible_en_el_listado(): void
    {
        $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Ventas por cliente y mes',
            'config' => $this->configValida(),
        ])->assertCreated();

        $this->getJson(route('informes.ventas.pivot.vistas.index'))
            ->assertOk()
            ->assertJsonPath('data.0.descripcion', 'Ventas por cliente y mes')
            ->assertJsonPath('data.0.config.dato', 'total_venta');
    }

    public function test_una_vista_de_ventas_no_aparece_en_el_listado_de_compras(): void
    {
        // Invariante 6 del data-model: los dos informes tienen sus propias pestañas.
        $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Sólo de Ventas',
            'config' => $this->configValida(),
        ])->assertCreated();

        $this->getJson(route('informes.compras.pivot.vistas.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_rechaza_una_accion_incompatible_con_el_dato(): void
    {
        // FR-014: sobre un conteo de filas la única acción con sentido es Suma. Se valida en el
        // servidor y no sólo en el cliente, porque un POST fuera de la UI la saltearía.
        $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Promedio de un conteo',
            'config' => $this->configValida(['dato' => 'cantidad_ventas', 'accion' => 'promedio']),
        ])->assertStatus(422)->assertJsonValidationErrors('config.accion');
    }

    public function test_acepta_suma_sobre_un_conteo(): void
    {
        $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Cantidad de ventas por cliente',
            'config' => $this->configValida(['dato' => 'cantidad_ventas', 'accion' => 'suma']),
        ])->assertCreated();
    }

    public function test_rechaza_descripcion_vacia(): void
    {
        $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => '',
            'config' => $this->configValida(),
        ])->assertStatus(422)->assertJsonValidationErrors('descripcion');
    }

    public function test_rechaza_una_dimension_que_no_existe_en_ese_informe(): void
    {
        // "vendedores" existe en Ventas pero NO en Compras: el modelo de Compras no lo tiene.
        $this->postJson(route('informes.compras.pivot.vistas.store'), [
            'descripcion' => 'Compras por vendedor',
            'config' => $this->configValida(['filas' => ['vendedores'], 'dato' => 'total_compra']),
        ])->assertStatus(422)->assertJsonValidationErrors('config.filas.0');
    }

    public function test_una_descripcion_repetida_se_rechaza(): void
    {
        // Antes se aceptaba avisando. Con la edición de vistas (28/08/2026) el duplicado dejó de
        // ser un nombre incómodo y pasó a ser una trampa: quedaban dos pestañas con el mismo
        // nombre y ninguna forma de saber cuál era la buena.
        $cuerpo = ['descripcion' => 'Mi informe', 'config' => $this->configValida()];

        $this->postJson(route('informes.ventas.pivot.vistas.store'), $cuerpo)->assertCreated();

        $this->postJson(route('informes.ventas.pivot.vistas.store'), $cuerpo)
            ->assertStatus(422)
            ->assertJsonValidationErrors('descripcion');

        $this->assertSame(1, InformeVista::porInforme('ventas')->count());
    }

    public function test_el_mismo_nombre_en_ventas_y_en_compras_no_es_duplicado(): void
    {
        // La unicidad es POR INFORME: Compras tiene sus propias dimensiones y medidas, así que la
        // config no se puede reusar tal cual.
        $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Mi informe',
            'config' => $this->configValida(),
        ])->assertCreated();

        $this->postJson(route('informes.compras.pivot.vistas.store'), [
            'descripcion' => 'Mi informe',
            'config' => $this->configValida([
                'filas' => ['proveedores'],
                'dato' => 'total_compra',
            ]),
        ])->assertCreated();
    }

    public function test_actualizar_una_vista_no_crea_otra(): void
    {
        // El bug original: editar un informe guardado y volver a guardarlo creaba uno nuevo.
        $id = $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Ventas por cliente',
            'config' => $this->configValida(),
        ])->json('data.id');

        $config = $this->configValida();
        $config['columnas'] = [];

        $this->putJson(route('informes.ventas.pivot.vistas.update', $id), [
            'descripcion' => 'Ventas por cliente',
            'config' => $config,
        ])->assertOk();

        $this->assertSame(1, InformeVista::porInforme('ventas')->count());
        $this->assertSame([], InformeVista::find($id)->config['columnas']);
    }

    public function test_actualizar_permite_renombrar_la_vista(): void
    {
        $id = $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Nombre viejo',
            'config' => $this->configValida(),
        ])->json('data.id');

        // Conservar el nombre propio no puede leerse como duplicado de sí misma.
        $this->putJson(route('informes.ventas.pivot.vistas.update', $id), [
            'descripcion' => 'Nombre viejo',
            'config' => $this->configValida(),
        ])->assertOk();

        $this->putJson(route('informes.ventas.pivot.vistas.update', $id), [
            'descripcion' => 'Nombre nuevo',
            'config' => $this->configValida(),
        ])->assertOk();

        $this->assertSame('Nombre nuevo', InformeVista::find($id)->descripcion);
    }

    public function test_actualizar_rechaza_el_nombre_de_otra_vista(): void
    {
        $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Ya existe',
            'config' => $this->configValida(),
        ])->assertCreated();

        $id = $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'La que edito',
            'config' => $this->configValida(),
        ])->json('data.id');

        $this->putJson(route('informes.ventas.pivot.vistas.update', $id), [
            'descripcion' => 'Ya existe',
            'config' => $this->configValida(),
        ])->assertStatus(422)->assertJsonValidationErrors('descripcion');
    }

    public function test_no_se_puede_actualizar_una_vista_de_ventas_desde_el_endpoint_de_compras(): void
    {
        // Mismo aislamiento que `destroy`: cambiar el id en la URL no cruza los informes.
        $id = $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'De ventas',
            'config' => $this->configValida(),
        ])->json('data.id');

        $this->putJson(route('informes.compras.pivot.vistas.update', $id), [
            'descripcion' => 'Secuestrada',
            'config' => $this->configValida(),
        ])->assertNotFound();

        $this->assertSame('De ventas', InformeVista::find($id)->descripcion);
    }

    public function test_actualizar_valida_la_combinacion_de_dato_y_accion(): void
    {
        $id = $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Para romper',
            'config' => $this->configValida(),
        ])->json('data.id');

        $config = $this->configValida();
        $config['dato'] = 'cantidad_ventas';
        $config['accion'] = 'promedio';

        $this->putJson(route('informes.ventas.pivot.vistas.update', $id), [
            'descripcion' => 'Para romper',
            'config' => $config,
        ])->assertStatus(422);
    }

    public function test_borrar_una_vista_la_saca_del_listado(): void
    {
        $id = $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Para borrar',
            'config' => $this->configValida(),
        ])->json('data.id');

        $this->deleteJson(route('informes.ventas.pivot.vistas.destroy', $id))->assertNoContent();

        $this->assertDatabaseMissing('informes_vistas', ['id' => $id]);
    }

    public function test_no_se_puede_borrar_una_vista_de_ventas_desde_el_endpoint_de_compras(): void
    {
        $id = $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'De Ventas',
            'config' => $this->configValida(),
        ])->json('data.id');

        $this->deleteJson(route('informes.compras.pivot.vistas.destroy', $id))->assertNotFound();

        $this->assertDatabaseHas('informes_vistas', ['id' => $id]);
    }

    public function test_las_vistas_son_compartidas_entre_usuarios(): void
    {
        // FR-034: se guarda quién la creó para auditoría, pero cualquiera con el permiso la ve.
        $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'La creó otro',
            'config' => $this->configValida(),
        ])->assertCreated();

        $otro = $this->autenticarConPermisoInformes();

        $this->getJson(route('informes.ventas.pivot.vistas.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.descripcion', 'La creó otro');

        $this->assertNotSame($otro->id, InformeVista::first()->creado_por_id);
    }

    public function test_guarda_quien_la_creo(): void
    {
        $this->postJson(route('informes.ventas.pivot.vistas.store'), [
            'descripcion' => 'Con autor',
            'config' => $this->configValida(),
        ])->assertCreated();

        $this->assertSame(auth()->id(), InformeVista::first()->creado_por_id);
    }

    public function test_borrar_al_usuario_que_la_creo_no_borra_la_vista(): void
    {
        // `nullOnDelete`: la vista es de todos, no de quien la creó.
        $autor = User::factory()->create();
        $vista = InformeVista::create([
            'informe' => 'ventas',
            'descripcion' => 'Queda huérfana',
            'config' => $this->configValida(),
            'creado_por_id' => $autor->id,
        ]);

        $autor->delete();

        $this->assertDatabaseHas('informes_vistas', ['id' => $vista->id, 'creado_por_id' => null]);
    }
}
