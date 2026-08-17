{{--
    Barra de pestañas del informe: detalle · Rankings · Arma tu Informe (spec 069, FR-001).

    Cada pestaña tiene URL real y no un fragmento `#`, para que se pueda compartir el enlace de un
    ranking (FR-004). El cambio no recarga: el JS hace `history.pushState` y muestra el panel que
    corresponde, porque los filtros y el rango son los mismos para el detalle y para el cruce.

    @param string $informe   'ventas' | 'compras'
    @param array  $rankings  clave de dimensión => rótulo
--}}
<ul class="nav nav-tabs mb-3" id="pestanas-informe" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" href="{{ route('informes.'.$informe.'.index') }}"
           data-panel="detalle">Informe de {{ $informe === 'ventas' ? 'Ventas' : 'Compras' }}</a>
    </li>

    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button">Rankings</a>
        <ul class="dropdown-menu">
            @foreach ($rankings as $clave => $rotulo)
                <li>
                    <a class="dropdown-item js-abrir-ranking" data-dimension="{{ $clave }}"
                       href="{{ route('informes.'.$informe.'.ranking.show', $clave) }}">{{ $rotulo }}</a>
                </li>
            @endforeach
        </ul>
    </li>

    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button">Arma tu Informe</a>
        <ul class="dropdown-menu" id="menu-vistas-guardadas">
            <li><a class="dropdown-item js-crear-informe" href="#">Crear Informe</a></li>
            {{-- Las vistas guardadas se agregan acá por JS al cargar la pantalla. --}}
        </ul>
    </li>

    {{-- Una pestaña suelta por cada vista guardada, a continuación (FR-001). Las agrega el JS. --}}
</ul>
