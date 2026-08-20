<!DOCTYPE html>
<html lang="en">

<head>
@if(isset($baseHref)) <base href="../"> @endif

<title><?php echo !empty(config('dz.pagelevel.'.$CurrentPage.'.title')) ? config('dz.pagelevel.'.$CurrentPage.'.title').' | ' : '' ; echo config('dz.site_level.site_title'); ?></title>

@include('elements.meta', ['CurrentPage' => $CurrentPage])
<link rel="shortcut icon" type="image/png" href="{{ asset(config('dz.site_level.favicon')) }}">

@include('elements.page-css', ['CurrentPage' => $CurrentPage])

{{-- Calendario de los campos dd/mm/aaaa (`AppFecha`). Va global y no por pagelevel porque hay
     campos de fecha en casi todos los módulos; declararlo pantalla por pantalla ya nos dejó
     ventas y compras con el campo sin calendario. --}}
<link href="{{ asset('vendor/bootstrap-datepicker-master/css/bootstrap-datepicker.min.css') }}" rel="stylesheet">

</head>

<body>
<script>
	try {
		if (localStorage.getItem('contagram-theme') === 'dark') {
			document.body.setAttribute('data-theme-version', 'dark');
		}
	} catch (e) {}
</script>

	<!--*******************
        Preloader start
    ********************-->
@include('elements.preloader')	
	<!--*******************
        Preloader end
    ********************-->

	<!--**********************************
        Main wrapper start
    ***********************************-->
                @php
    $classConfig = config('dz.pagelevel.'.$CurrentPage.'.mainwrapperclass');
@endphp
                            

	<div id="main-wrapper" @if(isset($classConfig)) class="{{$classConfig}}" @endif>
		<!--**********************************
            Nav header start
        ***********************************-->
@include('elements.nav-header')
		{{-- El sidebar recuerda si quedó colapsado, por navegador (localStorage), igual que el
		     tema oscuro de más arriba. Va inline y acá —apenas existen el wrapper y el
		     hamburger, antes del sidebar— y no en un bundle: si se espera al DOMContentLoaded
		     la barra se pinta abierta y se cierra de golpe a la vista del usuario.
		     El estado es el que el template ya usa: `menu-toggle` en #main-wrapper e
		     `is-active` en el hamburger (ver handleNavigation en public/js/custom.js). --}}
		<script>
			try {
				if (localStorage.getItem('contagram-sidebar') === 'colapsado') {
					document.getElementById('main-wrapper').classList.add('menu-toggle');
					document.querySelector('.hamburger').classList.add('is-active');
				}
			} catch (e) {}
		</script>
		<!--**********************************
            Nav header end
        ***********************************-->

		<!--**********************************
            Chat box start
        ***********************************-->
@include('elements.chatbox')
		<!--**********************************
            Chat box End
        ***********************************-->

		<!--**********************************
            Header start
        ***********************************-->
@include('elements.header', ['CurrentPage' => $CurrentPage])
		<!--**********************************
            Header end ti-comment-alt
        ***********************************-->

		<!--**********************************
            Sidebar start
        ***********************************-->
@include('elements.sidebar')
		<!--**********************************
            Sidebar end
        ***********************************-->

@yield('content')

@include('elements.modal-pdf')
@include('elements.btn-loading')

    <!--**********************************
            Footer start
        ***********************************-->
        @include('elements.footer')
        <!--**********************************
            Footer end
        ***********************************-->
	</div>
	<!--**********************************
        Main wrapper end
    ***********************************-->



@include('elements.page-js', ['CurrentPage' => $CurrentPage])

{{-- Utilidad global de inputs de fecha en dd/mm/aaaa (`AppFecha`). Va acá, y no vista por vista,
     porque hay campos de fecha en casi todos los módulos y porque se auto-inicializa sobre
     cualquier `[data-fecha-ar]` del documento y de los modales. Tiene que cargar ANTES de los
     bundles de pantalla (`local-js`), que la usan al inicializar. --}}
<script src="{{ asset('vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js') }}"></script>
<script src="{{ asset('vendor/bootstrap-datepicker-master/locales/bootstrap-datepicker.es.min.js') }}"></script>
@vite(['resources/js/fecha-ar.js'])

{{-- Buscador de catálogo con foco persistente (spec 071): widget genérico usado hoy en
     `#f-producto` de Venta/Compra/Presupuesto. Va acá por el mismo motivo que `fecha-ar.js`:
     tiene que cargar ANTES que los bundles de pantalla que lo montan. --}}
@vite(['resources/js/buscador-catalogo.js'])

{{-- Guarda el estado del sidebar cada vez que se lo abre/cierra; el que lo aplica al cargar es
     el script inline de arriba. El toggle de la clase lo sigue haciendo el template
     (`handleNavigation`), acá sólo se lee el resultado — de ahí el `setTimeout`, para leer
     DESPUÉS de que el handler del template haya corrido. --}}
<script>
	document.querySelector('.nav-control')?.addEventListener('click', function () {
		setTimeout(function () {
			try {
				localStorage.setItem(
					'contagram-sidebar',
					document.getElementById('main-wrapper').classList.contains('menu-toggle') ? 'colapsado' : 'abierto'
				);
			} catch (e) {}
		}, 0);
	});
</script>

@yield('local-js')
</body>

</html>