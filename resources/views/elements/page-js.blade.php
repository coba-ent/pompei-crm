<script>var enableSupportButton = '{{ config('dz.site_level.support_button') }}';</script>

@php
if (! function_exists('loadScripts')) {
    function loadScripts($scripts) {
        foreach ((array) $scripts as $script) {
            if (is_string($script)) {
                echo '<script src="' . asset($script) . '" type="text/javascript"></script>' . PHP_EOL;
            }
        }
    }
}

loadScripts(config('dz.global.js.top'));
loadScripts(config('dz.pagelevel.' . $CurrentPage . '.js'));
loadScripts(config('dz.global.js.bottom'));
loadScripts(config('dz.pagelevel.' . $CurrentPage . '.js.bottom'));
@endphp
