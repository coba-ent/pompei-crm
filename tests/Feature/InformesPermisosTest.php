<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Permisos granulares por informe (spec 090).
 *
 * Cubre las 65 rutas del módulo Informes según `contracts/rutas-permisos.md`. El foco está en dos
 * cosas distintas:
 *
 * 1. **La regresión**: `informes/stock/*` y `informes/cuenta-corriente/*` (Clientes) quedaron fuera
 *    del middleware y hoy los abre cualquier usuario autenticado pegando la URL. El `@can` del
 *    sidebar sólo escondía el enlace.
 * 2. **El aislamiento**: tener un permiso de informe no puede dar acceso a los otros siete.
 */
class InformesPermisosTest extends TestCase
{
    use RefreshDatabase;

    /** Los ocho informes con sus rutas de pantalla y de datos (sin descargas). */
    public static function informesProvider(): array
    {
        return [
            'ventas' => ['informes.ventas', [
                '/informes/ventas',
                '/informes/ventas/data',
                '/informes/ventas/stats',
                '/informes/ventas/pivot/dataset',
                '/informes/ventas/pivot/vistas',
            ]],
            'compras' => ['informes.compras', [
                '/informes/compras',
                '/informes/compras/data',
                '/informes/compras/stats',
                '/informes/compras/pivot/dataset',
                '/informes/compras/pivot/vistas',
            ]],
            'gastos' => ['informes.gastos', [
                '/informes/gastos',
                '/informes/gastos/data',
                '/informes/gastos/stats',
            ]],
            'stock' => ['informes.stock', [
                '/informes/stock',
                '/informes/stock/data',
                '/informes/stock/stats',
            ]],
            'cuenta-corriente-clientes' => ['informes.cuenta-corriente-clientes', [
                '/informes/cuenta-corriente',
                '/informes/cuenta-corriente/saldos',
                '/informes/cuenta-corriente/movimientos',
            ]],
            'cuenta-corriente-proveedores' => ['informes.cuenta-corriente-proveedores', [
                '/informes/cuenta-corriente-proveedores',
                '/informes/cuenta-corriente-proveedores/saldos',
                '/informes/cuenta-corriente-proveedores/movimientos',
            ]],
            'reporte-final' => ['informes.reporte-final', [
                '/informes/reporte-final',
                '/informes/reporte-final/data',
            ]],
            'contador' => ['informes.contador', [
                '/informes/contador',
            ]],
        ];
    }

    /** Rutas de descarga: exigen el permiso del informe **y** `informes.exportar`. */
    public static function descargasProvider(): array
    {
        return [
            'ventas excel' => ['informes.ventas', '/informes/ventas/exportar'],
            'ventas detallado' => ['informes.ventas', '/informes/ventas/exportar-detallado'],
            'ventas pdf' => ['informes.ventas', '/informes/ventas/pdf'],
            'compras excel' => ['informes.compras', '/informes/compras/exportar'],
            'compras pdf' => ['informes.compras', '/informes/compras/pdf'],
            'gastos excel' => ['informes.gastos', '/informes/gastos/exportar'],
            'gastos pdf' => ['informes.gastos', '/informes/gastos/pdf'],
            'cta cte clientes excel' => ['informes.cuenta-corriente-clientes', '/informes/cuenta-corriente/exportar'],
            'cta cte clientes pdf' => ['informes.cuenta-corriente-clientes', '/informes/cuenta-corriente/pdf'],
            'cta cte clientes mov excel' => ['informes.cuenta-corriente-clientes', '/informes/cuenta-corriente/movimientos/exportar'],
            'cta cte clientes mov pdf' => ['informes.cuenta-corriente-clientes', '/informes/cuenta-corriente/movimientos/pdf'],
            'cta cte prov excel' => ['informes.cuenta-corriente-proveedores', '/informes/cuenta-corriente-proveedores/exportar'],
            'cta cte prov pdf' => ['informes.cuenta-corriente-proveedores', '/informes/cuenta-corriente-proveedores/pdf'],
            'reporte final excel' => ['informes.reporte-final', '/informes/reporte-final/exportar'],
            'reporte final pdf' => ['informes.reporte-final', '/informes/reporte-final/pdf'],
            'contador ventas excel' => ['informes.contador', '/informes/contador/ventas/exportar'],
            'contador compras excel' => ['informes.contador', '/informes/contador/compras/exportar'],
            'contador iva digital' => ['informes.contador', '/informes/contador/iva-digital'],
        ];
    }

    /** Crea un usuario cuyo rol tiene exactamente los permisos indicados. */
    private function usuarioCon(array $codigos): User
    {
        $permisos = collect($codigos)->map(fn (string $codigo) => Permiso::firstOrCreate(
            ['codigo' => $codigo],
            ['descripcion' => $codigo, 'modulo' => explode('.', $codigo)[0]]
        ));

        $rol = Rol::create(['nombre' => 'Rol '.uniqid(), 'descripcion' => 'test', 'es_sistema' => false]);
        $rol->permisos()->sync($permisos->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->sync([$rol->id]);

        return $user;
    }

    #[DataProvider('informesProvider')]
    public function test_sin_el_permiso_del_informe_recibe_403(string $permiso, array $rutas): void
    {
        $user = $this->usuarioCon([]);

        foreach ($rutas as $ruta) {
            $this->actingAs($user)->get($ruta)->assertForbidden();
        }
    }

    /**
     * Con el permiso del informe, la autorización deja pasar.
     *
     * Se afirma "no es 403", no "es 200": varias rutas exigen parámetros obligatorios (p. ej.
     * `gastos/grupo` pide categoría/subcategoría) y sin ellos responden 422/302 por **validación**,
     * que es una capa posterior. Lo que esta feature gobierna es la autorización.
     */
    #[DataProvider('informesProvider')]
    public function test_con_el_permiso_del_informe_accede(string $permiso, array $rutas): void
    {
        $user = $this->usuarioCon([$permiso]);

        foreach ($rutas as $ruta) {
            $this->actingAs($user)->get($ruta)->assertOk();
        }
    }

    /**
     * Rutas que exigen parámetros: sin ellos responden por validación (422/302), nunca 403. Se
     * verifica que la autorización las deja pasar y que sin el permiso sí cortan.
     */
    public function test_rutas_con_parametros_obligatorios_pasan_la_autorizacion(): void
    {
        $conPermiso = $this->usuarioCon(['informes.gastos']);
        $this->actingAs($conPermiso)->get('/informes/gastos/grupo')->assertStatus(302);

        $sinPermiso = $this->usuarioCon([]);
        $this->actingAs($sinPermiso)->get('/informes/gastos/grupo')->assertForbidden();
    }

    /**
     * Aislamiento: tener UN permiso de informe no abre ninguno de los otros siete.
     *
     * Es el corazón de la feature — sin esto, "granular" sería sólo una etiqueta.
     */
    #[DataProvider('informesProvider')]
    public function test_un_permiso_de_informe_no_da_acceso_a_los_otros(string $permiso, array $rutasPropias): void
    {
        $user = $this->usuarioCon([$permiso]);

        foreach (self::informesProvider() as $otro) {
            [$otroPermiso, $otrasRutas] = $otro;

            if ($otroPermiso === $permiso) {
                continue;
            }

            foreach ($otrasRutas as $ruta) {
                $this->actingAs($user)->get($ruta)->assertForbidden();
            }
        }
    }

    /**
     * LA REGRESIÓN QUE MOTIVA LA SPEC.
     *
     * Antes de esta feature, estas 10 rutas respondían 200 a cualquier usuario autenticado: no
     * estaban dentro de ningún grupo con middleware de permiso. Un vendedor podía exportar la cuenta
     * corriente completa de todos los clientes escribiendo la URL a mano.
     */
    public function test_las_rutas_antes_desprotegidas_ahora_exigen_permiso(): void
    {
        $user = $this->usuarioCon([]);

        $rutasDelBug = [
            '/informes/stock',
            '/informes/stock/data',
            '/informes/stock/stats',
            '/informes/cuenta-corriente',
            '/informes/cuenta-corriente/saldos',
            '/informes/cuenta-corriente/movimientos',
            '/informes/cuenta-corriente/exportar',
            '/informes/cuenta-corriente/pdf',
            '/informes/cuenta-corriente/movimientos/exportar',
            '/informes/cuenta-corriente/movimientos/pdf',
        ];

        foreach ($rutasDelBug as $ruta) {
            $this->actingAs($user)->get($ruta)->assertForbidden();
        }
    }

    #[DataProvider('descargasProvider')]
    public function test_descarga_sin_permiso_de_exportar_recibe_403(string $permisoInforme, string $ruta): void
    {
        $user = $this->usuarioCon([$permisoInforme]);

        $this->actingAs($user)->get($ruta)->assertForbidden();
    }

    /** `informes.exportar` por sí solo no da acceso a ningún informe (FR-003). */
    #[DataProvider('descargasProvider')]
    public function test_exportar_sin_permiso_del_informe_recibe_403(string $permisoInforme, string $ruta): void
    {
        $user = $this->usuarioCon(['informes.exportar']);

        $this->actingAs($user)->get($ruta)->assertForbidden();
    }

    /**
     * Con ambos permisos la autorización deja pasar la descarga.
     *
     * Igual que arriba: se afirma "no es 403". Algunas descargas exigen parámetros (el período del
     * Libro IVA, por ejemplo) y sin ellos responden 422 por validación, que es capa posterior.
     */
    #[DataProvider('descargasProvider')]
    public function test_con_ambos_permisos_la_descarga_pasa_la_autorizacion(string $permisoInforme, string $ruta): void
    {
        $user = $this->usuarioCon([$permisoInforme, 'informes.exportar']);

        $status = $this->actingAs($user)->get($ruta)->getStatusCode();

        $this->assertNotSame(403, $status, "La descarga {$ruta} no debería cortar por permiso.");
    }

    /** Las vistas guardadas y los rankings se rigen por el permiso de su informe (FR-019, FR-021). */
    public function test_vistas_guardadas_y_rankings_siguen_el_permiso_de_su_informe(): void
    {
        $user = $this->usuarioCon(['informes.compras']);

        // Compras sí: puede listar y guardar cruces sobre el informe que ve.
        $this->actingAs($user)->get('/informes/compras/pivot/vistas')->assertSuccessful();
        $this->actingAs($user)->get('/informes/compras/ranking/proveedor')->assertSuccessful();

        // Ventas no.
        $this->actingAs($user)->get('/informes/ventas/pivot/vistas')->assertForbidden();
        $this->actingAs($user)->get('/informes/ventas/ranking/cliente')->assertForbidden();
        $this->actingAs($user)->post('/informes/ventas/pivot/vistas', [])->assertForbidden();
    }

    /** El envío al contador por correo va bajo `informes.contador`, sin exigir descarga (FR-012). */
    public function test_envio_al_contador_exige_el_permiso_del_informe(): void
    {
        $sinPermiso = $this->usuarioCon([]);
        $this->actingAs($sinPermiso)->post('/informes/contador/adjuntos-previstos', [])->assertForbidden();
        $this->actingAs($sinPermiso)->post('/informes/contador/enviar', [])->assertForbidden();
    }

    /** El rol Admin pasa cualquier permiso por `Gate::before` / `esAdmin()` (FR-027). */
    public function test_el_rol_admin_accede_a_todos_los_informes(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->sync([Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id]);

        foreach (self::informesProvider() as $caso) {
            foreach ($caso[1] as $ruta) {
                $this->actingAs($admin)->get($ruta)->assertOk();
            }
        }

        // Las descargas se afirman como "no 403": algunas exigen parámetros y sin ellos responden
        // por validación, que es una capa posterior a la autorización.
        foreach (self::descargasProvider() as $caso) {
            $status = $this->actingAs($admin)->get($caso[1])->getStatusCode();

            $this->assertNotSame(403, $status, "Admin no debería recibir 403 en {$caso[1]}.");
        }
    }

    /** Un usuario sin ningún permiso de informe no ve el bloque "Informes" del sidebar (FR-016). */
    public function test_el_sidebar_no_muestra_el_bloque_informes_sin_permisos(): void
    {
        $user = $this->usuarioCon([]);

        $this->actingAs($user)->get('/dashboard')->assertSuccessful()->assertDontSee('informes/stock');
    }
}
