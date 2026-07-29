{{--
    Opciones de un <select> de depósitos, con el depósito POR DEFECTO del CRM
    preseleccionado y rotulado como tal.

    Por qué existe este parcial: los selects se listan alfabéticamente (cómodo
    para buscar), pero el sistema toma como "por defecto" el primero por orden de
    alta (Deposito::porDefecto()). Si el select deja seleccionado el primero
    alfabético, se cargan movimientos en un depósito distinto del que usan las
    Ventas y la publicación de stock en Mercado Libre, y el desfasaje no da
    ningún error: simplemente el stock publicado queda mal (pasó el 28/07/2026,
    ver MERCADOLIBRE_NOTAS_TECNICAS.md §10).

    Parámetros:
      $depositos     Collection de depósitos activos (ordenados como se quiera mostrar)
      $seleccionado  (opcional) id a preseleccionar en vez del por defecto
--}}
@php
    $idPorDefecto = \App\Models\Deposito::porDefecto()?->id;
    $idSeleccionado = $seleccionado ?? $idPorDefecto;
@endphp
@foreach ($depositos as $deposito)
    <option value="{{ $deposito->id }}" @selected($deposito->id === $idSeleccionado)>
        {{ $deposito->nombre }}@if ($deposito->id === $idPorDefecto) (por defecto)@endif
    </option>
@endforeach
