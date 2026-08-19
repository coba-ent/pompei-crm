<?php

/**
 * Arranque compartido de los scripts de chequeo de stock.
 *
 * Los scripts corren en el VPS (`cd /var/www/contagram && php scripts/stock/<x>.php`),
 * así que el bootstrap se resuelve relativo a este archivo y funciona igual en local.
 */

$raiz = dirname(__DIR__, 2);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/**
 * Token de la cuenta de Mercado Libre conectada.
 *
 * OJO: `access_token` está cifrado en la base, así que hay que leerlo por el modelo.
 * `DB::table('ml_cuentas')->value('access_token')` devuelve el texto cifrado y además
 * puede agarrar una cuenta vieja: el estado que vale es `conectada`.
 */
function mlToken(): string
{
    return \App\Models\Integraciones\MercadoLibreCuenta::where('estado', 'conectada')->firstOrFail()->access_token;
}

/**
 * Movimientos de un comprobante. `origen_type` guarda la clase con backslashes, y
 * escribirla literal a través de SSH + `mysql -e "…"` nunca matchea por el escapado.
 * Con `LIKE '%Venta'` el filtro es inmune a eso.
 */
function movimientosDe(string $clase, int $id): \Illuminate\Support\Collection
{
    return \Illuminate\Support\Facades\DB::table('movimientos_stock')
        ->where('origen_type', 'LIKE', '%'.$clase)
        ->where('origen_id', $id)
        ->get();
}

/** Nombre de cada depósito, para no imprimir ids sueltos. */
function depositos(): array
{
    return \Illuminate\Support\Facades\DB::table('depositos')->pluck('nombre', 'id')->all();
}

/** Depósito general de Mercado Libre (el "Local" de la configuración). */
function depositoMlId(): int
{
    return (int) \App\Models\Integraciones\MercadoLibreConfiguracion::actual()->depositoEfectivo()->id;
}

/** Depósito Full, o null si no está configurado. */
function depositoFullId(): ?int
{
    $d = \App\Models\Integraciones\MercadoLibreConfiguracion::actual()->depositoFullEfectivoONulo();

    return $d?->id;
}

function titulo(string $texto): void
{
    echo "\n".$texto."\n".str_repeat('=', mb_strlen($texto))."\n";
}
