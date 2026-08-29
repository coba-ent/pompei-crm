<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Rol;
use Database\Seeders\PermisoSeeder;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reparto de los permisos de informe sobre los roles existentes (spec 090).
 *
 * Verifica el estado que producen los seeders, que por FR-028 debe ser **el mismo** que produce la
 * migración sobre una base existente. Si seeder y migración divergen, los tests pasan mientras el
 * sistema real hace otra cosa — por eso se parte de los seeders y no de fixtures propias.
 */
class InformesPermisosMigracionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Estos tests verifican justamente lo que producen los seeders, así que hay que correrlos: el
     * `TestCase` base no los ejecuta.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);
    }

    /** Los cinco códigos que le corresponden al rol Contable. */
    private const CONTABLE = [
        'informes.compras',
        'informes.contador',
        'informes.cuenta-corriente-proveedores',
        'informes.exportar',
        'informes.gastos',
    ];

    /** Los cuatro informes que el Contable NO debe recibir: exceden su función. */
    private const CONTABLE_NO = [
        'informes.ventas',
        'informes.stock',
        'informes.cuenta-corriente-clientes',
        'informes.reporte-final',
    ];

    private function permisosDeInformes(string $rol): array
    {
        return Rol::where('nombre', $rol)->firstOrFail()
            ->permisos()->where('modulo', 'informes')
            ->orderBy('codigo')->pluck('codigo')->all();
    }

    public function test_el_permiso_unico_anterior_ya_no_existe(): void
    {
        $this->assertFalse(
            Permiso::where('codigo', 'informes.ver')->exists(),
            'El permiso `informes.ver` debe retirarse del catálogo.'
        );
    }

    public function test_el_catalogo_tiene_los_nueve_permisos_nuevos(): void
    {
        $codigos = Permiso::where('modulo', 'informes')->orderBy('codigo')->pluck('codigo')->all();

        $this->assertSame([
            'informes.compras',
            'informes.contador',
            'informes.cuenta-corriente-clientes',
            'informes.cuenta-corriente-proveedores',
            'informes.exportar',
            'informes.gastos',
            'informes.reporte-final',
            'informes.stock',
            'informes.ventas',
        ], $codigos);
    }

    public function test_el_rol_contable_recibe_los_informes_de_su_funcion(): void
    {
        $this->assertSame(self::CONTABLE, $this->permisosDeInformes('Contable'));
    }

    public function test_el_rol_contable_no_recibe_los_informes_que_exceden_su_funcion(): void
    {
        $tiene = $this->permisosDeInformes('Contable');

        foreach (self::CONTABLE_NO as $codigo) {
            $this->assertNotContains($codigo, $tiene);
        }
    }

    /**
     * El reparto no puede tener efectos colaterales sobre los otros módulos del rol: la migración
     * usa attach idempotente y no `sync` justamente por esto.
     */
    public function test_el_rol_contable_conserva_sus_permisos_de_otros_modulos(): void
    {
        $codigos = Rol::where('nombre', 'Contable')->firstOrFail()
            ->permisos()->pluck('codigo')->all();

        foreach ([
            'compras.ver', 'compras.crear', 'compras.editar',
            'gastos.ver', 'gastos.crear', 'gastos.editar',
            'tesoreria.ver', 'tesoreria.crear', 'tesoreria.editar',
            'proveedores.ver', 'proveedores.crear', 'proveedores.editar',
        ] as $codigo) {
            $this->assertContains($codigo, $codigos);
        }
    }

    /** El Vendedor hoy no tiene informes; la feature no es la oportunidad de ampliarle el acceso. */
    public function test_el_rol_vendedor_no_recibe_ningun_permiso_de_informe(): void
    {
        $this->assertSame([], $this->permisosDeInformes('Vendedor'));
    }

    public function test_el_rol_admin_tiene_todos_los_permisos_de_informe(): void
    {
        $this->assertCount(9, $this->permisosDeInformes('Admin'));
    }
}
