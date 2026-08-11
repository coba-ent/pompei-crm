<?php

namespace App\Services\Migracion;

use App\Models\CuentaTesoreria;

/**
 * Traduce los nombres de cuenta de Contagram a las cuentas de tesorería del CRM.
 *
 * Existe porque **matchear por nombre exacto no alcanza y además hace daño**: los importadores lo
 * hacían así y crearon 12 cuentas duplicadas sobre las que ya existían — `Visa` junto a `VISA` (con
 * 3.990 cobros del lado equivocado), `Amex` junto a `AMEX`, `Galicia` junto a `Banco Galicia`. Un
 * catálogo de cuentas duplicado no es cosmético: parte el saldo de una misma cuenta real en dos y
 * la caja deja de cerrar.
 *
 * El mapeo es **explícito y revisado con el usuario** (10/08/2026), no heurístico. Las decisiones
 * que no eran obvias se consultaron:
 *
 * - `Caja abajo` **no** es `Caja General`: son cuentas distintas, va a una propia.
 * - `Visa` de Contagram es `VISA` (a cobrar, tarjetas de clientes), no `VISA Corporativa`.
 *
 * Ante un nombre que no esté acá, se crea la cuenta: son datos reales y perder movimientos por un
 * nombre no contemplado sería peor que tener una cuenta de más.
 */
class CuentasDeTesoreria
{
    /**
     * Nombre en Contagram (normalizado) => nombre exacto de la cuenta en el CRM.
     *
     * Cubre tanto los archivos de `Cuentas/` como los valores de `Medio de Cobro`/`Medio de Pago`
     * de los Excel de ventas y compras, que usan nombres distintos para la misma cuenta.
     */
    private const MAPEO = [
        // --- archivos de Cuentas/ ---
        'mp' => 'Mercado Pago',
        'caja local' => 'Caja del Local',
        'caja abajo' => 'Caja General Abajo',
        'banco credicoop' => 'Banco Credicoop',
        'banco santander rio' => 'Banco Santander Río',
        'galicia' => 'Banco Galicia',
        'visa' => 'VISA',
        'amex' => 'AMEX',
        'mastercard' => 'Mastercard',
        'qr' => 'PAYWAY QR',
        'cheque de terceros' => 'Cheque de Terceros',
        'cheque propio' => 'Cheque Propio',
        'usd local_' => 'USD Local',
        'usd online' => 'USD Online',
        'cabal a pagar' => 'Cabal A Pagar',
        'visa credicoop a pagar' => 'Visa Credicoop A Pagar',

        // --- medios de cobro/pago de los Excel de ventas y compras ---
        'caja del local' => 'Caja del Local',
        'mercado pago' => 'Mercado Pago',
        'payway qr' => 'PAYWAY QR',
        'juan usd personal' => 'Juan USD Personal',
        'cabal acreditaciones' => 'Cabal Acreditaciones',
        'caja general abajo' => 'Caja General Abajo',
        'caja chica gastos' => 'Caja chica gastos',
        'cabal credicoop' => 'Cabal Credicoop',
        'visa credicoop' => 'Visa Credicoop',
        'usd online ' => 'USD Online',
        'maestro' => 'Maestro',
        'nulo' => 'Nulo',
        'cabal' => 'Cabal',
        'retenciones' => 'Retenciones',

        // --- medios de pago que sólo aparecen en Gastos ---
        // Contagram le agrega al nombre el tipo de cuenta. `Mastercard A Cobrar` es la Mastercard
        // del CRM, que ya es de tipo `a_cobrar`. `Cabal Credicoop A Pagar`, en cambio, NO es la
        // `Cabal Credicoop` existente: son cuentas de distinto tipo y se dejan separadas.
        'mastercard a cobrar' => 'Mastercard',
        'cabal credicoop a pagar' => 'Cabal Credicoop A Pagar',
    ];

    /** Tipo con que se crean las cuentas que todavía no existen. */
    private const TIPOS = [
        'Cabal' => 'banco',
        'Cabal A Pagar' => 'a_pagar',
        'Cabal Acreditaciones' => 'banco',
        'Cabal Credicoop' => 'banco',
        'Caja General Abajo' => 'efectivo',
        'Caja chica gastos' => 'efectivo',
        'Maestro' => 'banco',
        'Nulo' => 'efectivo',
        'Retenciones' => 'efectivo',
        'USD Local' => 'efectivo',
        'USD Online' => 'efectivo',
        'Visa Credicoop' => 'banco',
        'Visa Credicoop A Pagar' => 'a_pagar',
        'Cabal Credicoop A Pagar' => 'a_pagar',
    ];

    /** @var array<string,int> nombre del CRM => id */
    private array $cache = [];

    public function __construct()
    {
        $this->cache = CuentaTesoreria::pluck('id', 'nombre')->all();
    }

    /** Id de la cuenta del CRM para un nombre de Contagram, creándola si no existe. */
    public function resolver(?string $nombreContagram): int
    {
        $nombre = $this->nombreEnElCrm($nombreContagram);

        if (isset($this->cache[$nombre])) {
            return $this->cache[$nombre];
        }

        return $this->cache[$nombre] = CuentaTesoreria::create([
            'nombre' => $nombre,
            'tipo' => self::TIPOS[$nombre] ?? 'efectivo',
            'visible' => true,
            'saldo_inicial' => 0,
        ])->id;
    }

    /** Nombre de la cuenta del CRM, sin tocar la base. Útil para previsualizar el mapeo. */
    public function nombreEnElCrm(?string $nombreContagram): string
    {
        $clave = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $nombreContagram)));

        // Los archivos de Mercado Pago vienen partidos por año ("2021 MP" … "2026 MP").
        if (preg_match('/^(20\d\d )?mp$/', $clave)) {
            $clave = 'mp';
        }

        if ($clave === '') {
            return 'Sin especificar (migración)';
        }

        return self::MAPEO[$clave] ?? trim((string) $nombreContagram);
    }
}
