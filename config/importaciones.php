<?php

return [

    /*
    |---------------------------------------------------------------------------------------------
    | Plazo de conservación de los archivos importados (spec 093, FR-019)
    |---------------------------------------------------------------------------------------------
    |
    | Días que se conserva la copia del archivo subido antes de que `importaciones:limpiar-archivos`
    | la elimine. No se creó una tabla para un único número.
    |
    */

    'dias_conservacion_archivo' => (int) env('IMPORTACIONES_DIAS_CONSERVACION_ARCHIVO', 90),

];
