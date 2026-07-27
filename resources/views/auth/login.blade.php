@extends('layouts.fullwidth', ['CurrentPage' => 'page-login'])

@section('content')
<div class="authincation h-100">
	<div class="container-fluid h-100">
		<div class="row h-100">
			<div class="col-lg-6 col-md-12 col-sm-12 mx-auto align-self-center">
				<div class="login-form">
					<div class="text-center">
						<img src="{{ asset('images/logo/logo-full.png') }}" class="mb-3 login-sm-logo mx-auto" alt="Contagram CRM">
						<h3 class="title">Iniciar sesión</h3>
						<p>Ingresá a tu cuenta para usar Contagram CRM</p>
					</div>

					@if (session('status'))
						<div class="alert alert-success">{{ session('status') }}</div>
					@endif

					@if ($errors->any())
						<div class="alert alert-danger">{{ $errors->first() }}</div>
					@endif

					<form method="POST" action="{{ route('login') }}">
						@csrf

						<div class="mb-4">
							<label class="mb-1">Email<span class="text-danger"> *</span></label>
							<input type="email" name="email" value="{{ old('email') }}" class="form-control"
								required autofocus autocomplete="username">
						</div>
						<div class="mb-4 position-relative">
							<label class="mb-1">Contraseña<span class="text-danger"> *</span></label>
							<input type="password" id="dz-password" name="password" class="form-control"
								required autocomplete="current-password">
							<span class="show-pass eye">
								<i class="fa fa-eye-slash"></i>
								<i class="fa fa-eye"></i>
							</span>
						</div>
						<div class="form-row d-flex justify-content-between mt-4 mb-2">
							<div class="mb-4">
								<div class="form-check custom-checkbox mb-3">
									<input type="checkbox" class="form-check-input" name="remember" id="remember_me">
									<label class="form-check-label mt-1" for="remember_me">Recordarme</label>
								</div>
							</div>
						</div>
						<div class="text-center mb-4 d-grid">
							<button type="submit" class="btn btn-primary">Iniciar sesión</button>
						</div>
					</form>
				</div>
			</div>
			<div class="col-xl-6 col-lg-6">
				<div class="pages-left h-100">
					<div class="login-content">
						<img src="{{ asset('images/logo/logofull-white.png') }}" class="mb-3" alt="Contagram CRM">
						<p>Gestión integral de tu negocio: ingresos, egresos, facturación electrónica e informes en un solo lugar.</p>
					</div>
					<div class="login-media text-center">
						<img src="{{ asset('images/login.png') }}" alt="">
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
@endsection
