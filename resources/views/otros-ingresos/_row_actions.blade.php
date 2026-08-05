@php
    $badge = $otroIngreso->estado() === 'pendiente' ? 'warning' : 'success';
    $label = $otroIngreso->estado() === 'pendiente' ? 'Pendiente' : 'Cobrado';
@endphp
<div class="dropdown">
    <button class="btn btn-sm btn-outline-{{ $badge }} dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static">
        {{ $label }}
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item js-ver" href="#" data-id="{{ $otroIngreso->id }}">Ver</a></li>
        <li><a class="dropdown-item js-editar" href="#" data-id="{{ $otroIngreso->id }}">Editar</a></li>
        <li><a class="dropdown-item text-danger js-eliminar" href="#" data-id="{{ $otroIngreso->id }}">Eliminar</a></li>
    </ul>
</div>
