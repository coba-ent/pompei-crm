<?php

$total = DB::table('precios_producto')->where('lista_precio_id', 10)->count();
echo "Filas en lista ML Premium (id=10): $total\n";

$muestra = DB::table('precios_producto')->where('lista_precio_id', 10)->orderBy('id')->limit(8)->get(['id', 'producto_id', 'precio']);

foreach ($muestra as $m) {
    $nuevo = round($m->precio * 1.21, 2);
    echo "id={$m->id} | producto_id={$m->producto_id} | precio={$m->precio} -> $nuevo\n";
}
