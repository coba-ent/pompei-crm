@php
    $badge = $gasto->estado() === 'pendiente' ? 'warning' : 'success';
    $label = $gasto->estado() === 'pendiente' ? 'Pendiente' : 'Pagado';
@endphp
<div class="dropdown">
    <button class="btn btn-sm btn-outline-{{ $badge }} dropdown-toggle" type="button" data-bs-toggle="dropdown">
        {{ $label }}
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item js-ver" href="#" data-id="{{ $gasto->id }}">Ver</a></li>
        <li><a class="dropdown-item js-editar" href="#" data-id="{{ $gasto->id }}">Editar</a></li>
        <li><a class="dropdown-item text-danger js-eliminar" href="#" data-id="{{ $gasto->id }}">Eliminar</a></li>
    </ul>
</div>
