<!DOCTYPE html>
<html lang="en">

<head>
@if(isset($baseHref)) <base href="../"> @endif

<title><?php echo !empty(config('dz.pagelevel.'.$CurrentPage.'.title')) ? config('dz.pagelevel.'.$CurrentPage.'.title').' | ' : '' ; echo config('dz.site_level.site_title'); ?></title>

@include('elements.meta', ['CurrentPage' => $CurrentPage])
<link rel="shortcut icon" type="image/png" href="{{ asset(config('dz.site_level.favicon')) }}">

@include('elements.page-css', ['CurrentPage' => $CurrentPage])

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
                            

	{{-- "menu-toggle" = sidebar comprimida por defecto (pedido explícito del usuario). El botón
	     hamburguesa (public/js/custom.js handleNavigation) sigue togglando esta misma clase para
	     expandir/comprimir manualmente durante la sesión; no hay persistencia entre cargas. --}}
	<div id="main-wrapper" class="menu-toggle {{ $classConfig ?? '' }}">
		<!--**********************************
            Nav header start
        ***********************************-->
@include('elements.nav-header')
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

@yield('local-js')
</body>

</html>