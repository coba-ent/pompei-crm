@extends('layouts.fullwidth', ['CurrentPage' => 'page-login'])

@section('content')
<div class="authincation h-100">
	<div class="container-fluid h-100">
		<div class="row h-100">
			<div class="col-lg-6 col-md-12 col-sm-12 mx-auto align-self-center">
				<div class="login-form">
					<div class="text-center">
						<h3 class="title">Recuperar contraseña</h3>
					</div>

					@if (!$tokenValido)
						<div class="alert alert-danger">
							Este link ya no es válido o venció.
						</div>
						<div class="text-center mb-4 d-grid">
							<a href="{{ route('login') }}" class="btn btn-primary">Volver al login</a>
						</div>
					@else
						<p class="text-center text-muted">Definí tu nueva contraseña para <strong>{{ $email }}</strong></p>

						<form id="form-nueva-contrasena">
							<input type="hidden" name="token" value="{{ $token }}">
							<input type="hidden" name="email" value="{{ $email }}">

							<div class="mb-4">
								<label class="mb-1">Nueva contraseña<span class="text-danger"> *</span></label>
								<input type="password" name="password" class="form-control" required autocomplete="new-password">
							</div>
							<div class="mb-4">
								<label class="mb-1">Confirmar contraseña<span class="text-danger"> *</span></label>
								<input type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
							</div>
							<div class="text-center mb-4 d-grid">
								<button type="submit" class="btn btn-primary">Guardar nueva contraseña</button>
							</div>
						</form>
					@endif
				</div>
			</div>
			<div class="col-xl-6 col-lg-6">
				<div class="pages-left h-100" style="background-image: url('{{ asset('images/login.png') }}'); background-size: cover; background-position: center; background-repeat: no-repeat;"></div>
			</div>
		</div>
	</div>
</div>

@section('local-js')
<script>
	window.ResetPasswordConfig = {
		rutas: {
			actualizar: @json(route('contrasena.actualizar')),
			login: @json(route('login')),
		},
	};
</script>
@vite(['resources/js/auth-password.js'])
@endsection
@endsection
