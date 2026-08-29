<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permisos granulares por informe (spec 090).
 *
 * Reemplaza el permiso único `informes.ver` por ocho permisos por informe más `informes.exportar`
 * (transversal, habilita las descargas de lo que ya se puede ver).
 *
 * El reparto **no** es un traslado uniforme. Sobre el estado real de la base al 28/08/2026 —Admin
 * 3 usuarios, Vendedor 2 usuarios sin informes, Contable 0 usuarios con `informes.ver`— darle los
 * ocho al Contable le dejaría el Reporte Final (márgenes/CMV) y la Cta Cte de Clientes, que es
 * justamente lo que la feature busca evitar. Recibe en cambio los informes de los módulos que ya
 * administra. Ver `specs/090-permisos-granulares-informes/data-model.md`.
 *
 * Al rol Admin no se le asigna nada explícitamente: `User::tienePermiso()` corta antes por
 * `esAdmin()`, así que pasa cualquier permiso. (El `RolSeeder` igual le sincroniza todo.)
 */
return new class extends Migration
{
    /** Los ocho informes. `informes.exportar` va aparte porque no es un informe. */
    private const INFORMES = [
        'informes.ventas' => 'Ver el Informe de Ventas (incluye sus rankings y "Arma tu Informe")',
        'informes.compras' => 'Ver el Informe de Compras (incluye sus rankings y "Arma tu Informe")',
        'informes.gastos' => 'Ver el Informe de Gastos',
        'informes.stock' => 'Ver el Informe de Stock',
        'informes.cuenta-corriente-clientes' => 'Ver la Cuenta Corriente de Clientes',
        'informes.cuenta-corriente-proveedores' => 'Ver la Cuenta Corriente de Proveedores',
        'informes.reporte-final' => 'Ver el Reporte Final (incluye márgenes y costo de mercadería vendida)',
        'informes.contador' => 'Ver Información para tu Contador (Libro IVA, IVA Digital, envío al contador)',
    ];

    private const EXPORTAR = [
        'informes.exportar' => 'Exportar a Excel y generar PDF de los informes que ya pueda ver',
    ];

    /** Lo que recibe el rol Contable: los informes de los módulos que ya administra. */
    private const CONTABLE = [
        'informes.compras',
        'informes.gastos',
        'informes.cuenta-corriente-proveedores',
        'informes.contador',
        'informes.exportar',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            // 1. Crear los nueve permisos. Idempotente: el PermisoSeeder puede haber corrido ya.
            foreach (array_merge(self::INFORMES, self::EXPORTAR) as $codigo => $descripcion) {
                DB::table('permisos')->updateOrInsert(
                    ['codigo' => $codigo],
                    ['descripcion' => $descripcion, 'modulo' => 'informes', 'created_at' => now(), 'updated_at' => now()]
                );
            }

            $viejo = DB::table('permisos')->where('codigo', 'informes.ver')->value('id');

            // Base nueva ya sembrada por los seeders: no hay nada que trasladar.
            if ($viejo === null) {
                return;
            }

            $nuevos = DB::table('permisos')
                ->where('modulo', 'informes')
                ->where('codigo', '!=', 'informes.ver')
                ->pluck('id', 'codigo');

            // 2. Contable: sus cinco códigos. Attach idempotente, NO sync — tocar sus permisos de
            //    compras/gastos/proveedores/tesorería sería un efecto colateral inaceptable.
            $contable = DB::table('roles')->where('nombre', 'Contable')->value('id');
            if ($contable !== null) {
                $this->asignar($contable, $nuevos->only(self::CONTABLE)->values()->all());
            }

            // 3. Vendedor no recibe nada: hoy no tiene acceso a informes y la feature no es la
            //    oportunidad de ampliárselo.

            // 4. Cualquier OTRO rol que tenga el permiso viejo (creado a mano por el admin) recibe
            //    los nueve, para no quitarle ningún acceso vigente.
            $otros = DB::table('permiso_rol')
                ->join('roles', 'roles.id', '=', 'permiso_rol.rol_id')
                ->where('permiso_rol.permiso_id', $viejo)
                ->whereNotIn('roles.nombre', ['Admin', 'Vendedor', 'Contable'])
                ->pluck('roles.id');

            foreach ($otros as $rolId) {
                $this->asignar($rolId, $nuevos->values()->all());
            }

            // 5. Recién ahora se retira el permiso viejo, del pivot y del catálogo.
            DB::table('permiso_rol')->where('permiso_id', $viejo)->delete();
            DB::table('permisos')->where('id', $viejo)->delete();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('permisos')->updateOrInsert(
                ['codigo' => 'informes.ver'],
                ['descripcion' => 'Ver Informes', 'modulo' => 'informes', 'created_at' => now(), 'updated_at' => now()]
            );

            $viejo = DB::table('permisos')->where('codigo', 'informes.ver')->value('id');

            $nuevos = DB::table('permisos')
                ->where('modulo', 'informes')
                ->where('codigo', '!=', 'informes.ver')
                ->pluck('id');

            // Todo rol que tenga al menos uno de los nuevos vuelve a tener el viejo. No restituye
            // qué informes veía cada rol —eso es una decisión de negocio, no un dato derivable—
            // pero devuelve el sistema a un estado funcional equivalente al anterior.
            $roles = DB::table('permiso_rol')->whereIn('permiso_id', $nuevos)->distinct()->pluck('rol_id');

            foreach ($roles as $rolId) {
                $this->asignar($rolId, [$viejo]);
            }

            DB::table('permiso_rol')->whereIn('permiso_id', $nuevos)->delete();
            DB::table('permisos')->whereIn('id', $nuevos)->delete();
        });
    }

    /** Attach idempotente de permisos a un rol, sin tocar los que ya tiene. */
    private function asignar(int $rolId, array $permisoIds): void
    {
        $yaTiene = DB::table('permiso_rol')
            ->where('rol_id', $rolId)
            ->whereIn('permiso_id', $permisoIds)
            ->pluck('permiso_id')
            ->all();

        $faltantes = array_diff($permisoIds, $yaTiene);

        if ($faltantes === []) {
            return;
        }

        DB::table('permiso_rol')->insert(array_map(
            fn ($permisoId) => ['rol_id' => $rolId, 'permiso_id' => $permisoId],
            array_values($faltantes)
        ));
    }
};
