{{-- "Recordarme" del login: guarda (o borra) en el localStorage de este navegador la última
     cuenta con la que se entró con el check tildado. Lo lee resources/js/auth-password.js en
     la pantalla de login. Ver App\Services\Auth\CredencialRecordada.
     Va en sesión con `pull` (no flash) porque después del login puede haber redirects
     intermedios (ej. intended = "/" → "/dashboard") que consumirían un flash sin dibujar
     ninguna página. Va en los dos layouts porque la primera página dibujada puede ser
     cualquiera de ellos. --}}
@php
	$loginRecordado = session()->pull('login_recordado');
	$loginOlvidar = session()->pull('login_olvidar');
@endphp
@if ($loginRecordado)
<script>
	try {
		localStorage.setItem('pompei.login_recordado', JSON.stringify(@json($loginRecordado)));
	} catch (e) {}
</script>
@elseif ($loginOlvidar)
<script>
	try { localStorage.removeItem('pompei.login_recordado'); } catch (e) {}
</script>
@endif
