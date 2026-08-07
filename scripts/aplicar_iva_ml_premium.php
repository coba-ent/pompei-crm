<?php

$afectadas = DB::update('UPDATE precios_producto SET precio = ROUND(precio * 1.21, 2) WHERE lista_precio_id = 10');
echo "Filas actualizadas: $afectadas\n";

$muestra = DB::table('precios_producto')->where('lista_precio_id', 10)->orderBy('id')->limit(8)->get(['id', 'producto_id', 'precio']);
foreach ($muestra as $m) {
    echo "id={$m->id} | producto_id={$m->producto_id} | precio nuevo={$m->precio}\n";
}
