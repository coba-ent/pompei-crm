<script>var enableSupportButton = '{{ config('dz.site_level.support_button') }}';</script>

@php
// dzAssetVersionado() se define en elements/page-css.blade.php (cache busting por
// mtime). El guard cubre el caso de que este parcial se renderice sin aquél.
if (! function_exists('dzAssetVersionado')) {
    function dzAssetVersionado($ruta) {
        $absoluta = public_path($ruta);
        $version = is_file($absoluta) ? filemtime($absoluta) : null;

        return asset($ruta) . ($version ? '?v=' . $version : '');
    }
}

if (! function_exists('loadScripts')) {
    function loadScripts($scripts) {
        foreach ((array) $scripts as $script) {
            if (is_string($script)) {
                echo '<script src="' . dzAssetVersionado($script) . '" type="text/javascript"></script>' . PHP_EOL;
            }
        }
    }
}

loadScripts(config('dz.global.js.top'));
loadScripts(config('dz.pagelevel.' . $CurrentPage . '.js'));
loadScripts(config('dz.global.js.bottom'));
loadScripts(config('dz.pagelevel.' . $CurrentPage . '.js.bottom'));
@endphp
