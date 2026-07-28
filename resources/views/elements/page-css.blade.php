@php
/**
 * Versionado de assets estáticos de `public/` (cache busting).
 *
 * Sin esto, el navegador cachea `css/contagram-custom.css` (y cualquier otro
 * asset de esta lista) indefinidamente: un deploy que cambia el CSS no se ve
 * hasta que el usuario hace Ctrl+F5. Pasó con el fix de la flecha de
 * DataTables Responsive (28/07/2026): el archivo estaba bien en el server pero
 * el navegador seguía usando la copia vieja.
 *
 * Se agrega `?v=<mtime>` — cambia solo cuando el archivo cambia, así que el
 * navegador vuelve a pedirlo únicamente cuando hace falta. No aplica a los
 * assets de Vite (`public/build/`), que ya traen hash en el nombre.
 */
if (! function_exists('dzAssetVersionado')) {
    function dzAssetVersionado($ruta) {
        $absoluta = public_path($ruta);
        $version = is_file($absoluta) ? filemtime($absoluta) : null;

        return asset($ruta) . ($version ? '?v=' . $version : '');
    }
}

if (! function_exists('loadStyles')) {
    function loadStyles($styles) {
        foreach ((array) $styles as $style) {
            if (is_string($style)) {
                echo '<link href="' . dzAssetVersionado($style) . '" rel="stylesheet" type="text/css"/>' . PHP_EOL;
            }
        }
    }
}

loadStyles(config('dz.global.css.top'));
loadStyles(config('dz.pagelevel.' . $CurrentPage . '.css'));
loadStyles(config('dz.global.css.bottom'));
@endphp
