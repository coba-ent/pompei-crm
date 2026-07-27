@extends('layouts.fullwidth', ['CurrentPage' => 'page-error-400'])

@section('content')
	<div class="authincation h-100">
		<div class="container ">
			<div class="row justify-content-center h-100 align-items-center">
				<div class="col-md-6">
					<div class="error-page">
						<div class="error-inner text-center">
							<div class="dz-error" data-text="400">400</div>
							<h2 class="error-head">We are sorry. But the page you are looking for cannot be found.</h2>
								<a href="{{ url('index')}}" class="btn btn-secondary">BACK TO HOMEPAGE</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
@endsection