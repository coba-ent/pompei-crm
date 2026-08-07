		<div class="deznav">
			<div class="deznav-scroll grid-menu">
				<div class="text-center py-3">
					<img src="{{ asset('images/LOGARDO.webp') }}" alt="Logo" style="max-width: 140px; width: 100%; height: auto;">
				</div>
				<ul class="metismenu" id="menu">

					<li><a href="{{ route('dashboard.index') }}">
						<div class="menu-icon">
							<svg width="24" height="24" viewBox="0 0 16 16" fill="none"
								xmlns="http://www.w3.org/2000/svg">
								<path
									d="M8.354 1.146a.5.5 0 0 0-.708 0l-6 6A.5.5 0 0 0 1.5 7.5v7a.5.5 0 0 0 .5.5h4.5a.5.5 0 0 0 .5-.5v-4h2v4a.5.5 0 0 0 .5.5H14a.5.5 0 0 0 .5-.5v-7a.5.5 0 0 0-.146-.354L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293l-2.646-2.647z"
									fill="#6F767E" />
							</svg>
						</div>
						<span class="nav-text">Inicio</span>
					</a></li>
						{{-- Módulo Mensajería oculto a propósito (desactivado + webhook de ML desconectado, ver
						     MercadoLibreMensajeriaWebhookController::recibir). Reactivar: sacar este @if(false)
						     y volver a dejar el @can('mensajeria.ver') solo. --}}
						@if (false)
							@can('mensajeria.ver')
								<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
									<div class="menu-icon">
										<svg width="24" height="24" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M14 1a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H4.414A2 2 0 0 0 3 12.586l-2 2V3a2 2 0 0 1 2-2h11z" fill="#6F767E" />
										</svg>
									</div>
									<span class="nav-text">Mensajería</span>
								</a>
									<ul aria-expanded="false">
										<li><a href="{{ route('mensajeria.index') }}">Bandeja</a></li>
									</ul>
								</li>
							@endcan
						@endif

					
					<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
							<div class="menu-icon">
								<svg width="24" height="24" viewBox="0 0 16 16" fill="none"
									xmlns="http://www.w3.org/2000/svg">
									<path
										d="M1 3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1H1zm7 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"
										fill="#6F767E" />
									<path
										d="M0 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1V5zm3 0a2 2 0 0 1-2 2v4a2 2 0 0 1 2 2h10a2 2 0 0 1 2-2V7a2 2 0 0 1-2-2H3z"
										fill="#6F767E" />
								</svg>
							</div>
							<span class="nav-text">Ingresos</span>
						</a>
						<ul aria-expanded="false">
							@can('presupuestos.ver')
								<li class="d-flex align-items-center">
										<a href="{{ route('presupuestos.index') }}" class="flex-grow-1">Presupuestos</a>
										<a href="{{ route('presupuestos.create') }}" class="menu-crear-btn" title="Crear nuevo"><i class="fas fa-plus-circle"></i></a>
									</li>
							@endcan
							@can('ventas.ver')
								<li class="d-flex align-items-center">
										<a href="{{ route('ventas.index') }}" class="flex-grow-1">Ventas</a>
										<a href="{{ route('ventas.create') }}" class="menu-crear-btn" title="Crear nuevo"><i class="fas fa-plus-circle"></i></a>
									</li>
								@if (\App\Models\FuncionAvanzada::where('clave', 'mercadolibre')->value('activa'))
									<li><a href="{{ route('ingresos.mercadolibre.index') }}">Mercado Libre</a></li>
								@endif
								@if (\App\Models\FuncionAvanzada::where('clave', 'tiendanube')->value('activa'))
									<li><a href="{{ route('ingresos.tiendanube.index') }}">Tiendanube</a></li>
								@endif
							@endcan
							@can('otros-ingresos.ver')
								<li class="d-flex align-items-center">
										<a href="{{ route('otros-ingresos.index') }}" class="flex-grow-1">Otros Ingresos</a>
										<a href="{{ route('otros-ingresos.index', ['crear' => 1]) }}" class="menu-crear-btn" title="Crear nuevo"><i class="fas fa-plus-circle"></i></a>
									</li>
							@endcan
						</ul>
					</li>
					<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
						<div class="menu-icon">
							<svg width="24" height="24" viewBox="0 0 16 16" fill="none"
								xmlns="http://www.w3.org/2000/svg">
								<path
									d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"
									fill="#6F767E" />
								<path
									d="M8 4a.5.5 0 0 1 .5.5v5.793l2.146-2.147a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 1 1 .708-.708L7.5 10.293V4.5A.5.5 0 0 1 8 4z"
									fill="#6F767E" />
							</svg>
						</div>
						<span class="nav-text">Egresos</span>
					</a>
						<ul aria-expanded="false">
							@can('compras.ver')
								<li class="d-flex align-items-center">
										<a href="{{ route('compras.index') }}" class="flex-grow-1">Compras</a>
										<a href="{{ route('compras.create') }}" class="menu-crear-btn" title="Crear nuevo"><i class="fas fa-plus-circle"></i></a>
									</li>
							@endcan
							@can('gastos.ver')
								<li class="d-flex align-items-center">
										<a href="{{ route('gastos.index') }}" class="flex-grow-1">Gastos</a>
										<a href="{{ route('gastos.index', ['crear' => 1]) }}" class="menu-crear-btn" title="Crear nuevo"><i class="fas fa-plus-circle"></i></a>
									</li>
							@endcan
						</ul>
					</li>
					<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
							<div class="menu-icon">
								<svg width="24" height="24" viewBox="0 0 16 16" fill="none"
									xmlns="http://www.w3.org/2000/svg">
									<path
										d="M14 10a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1v-1a1 1 0 0 1 1-1h12zM2 9a2 2 0 0 0-2 2v1a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1a2 2 0 0 0-2-2H2z"
										fill="#6F767E" />
									<path
										d="M5 11.5a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0zm-2 0a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0zM14 3a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h12zM2 2a2 2 0 0 0-2 2v1a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H2z"
										fill="#6F767E" />
									<path
										d="M5 4.5a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0zm-2 0a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0z"
										fill="#6F767E" />
								</svg>
							</div>
							<span class="nav-text">Base de Datos</span>
						</a>
						<ul aria-expanded="false">
							@can('clientes.ver')
								<li class="d-flex align-items-center">
										<a href="{{ route('clientes.index') }}" class="flex-grow-1">Clientes</a>
										<a href="{{ route('clientes.index', ['crear' => 1]) }}" class="menu-crear-btn" title="Crear nuevo"><i class="fas fa-plus-circle"></i></a>
									</li>
							@endcan
							@can('proveedores.ver')
								<li class="d-flex align-items-center">
										<a href="{{ route('proveedores.index') }}" class="flex-grow-1">Proveedores</a>
										<a href="{{ route('proveedores.index', ['crear' => 1]) }}" class="menu-crear-btn" title="Crear nuevo"><i class="fas fa-plus-circle"></i></a>
									</li>
							@endcan
							@can('productos.ver')
								<li class="d-flex align-items-center">
										<a href="{{ route('productos.index') }}" class="flex-grow-1">Productos &amp; Servicios</a>
										<a href="{{ route('productos.index', ['crear' => 1]) }}" class="menu-crear-btn" title="Crear nuevo"><i class="fas fa-plus-circle"></i></a>
									</li>
							@endcan
						</ul>
					</li>
					<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
							<div class="menu-icon">
								<svg width="24" height="24" viewBox="0 0 16 16" fill="none"
									xmlns="http://www.w3.org/2000/svg">
									<path
										d="M4 11H2v3h2v-3zm5-4H7v7h2V7zm5-5v12h-2V2h2zm-2-1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1h-2zM6 7a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7zm-5 4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1v-3z"
										fill="#6F767E" />
								</svg>
							</div>
							<span class="nav-text">Informes</span>
						</a>
						<ul aria-expanded="false">
							@can('informes.ver')
								<li><a href="{{ route('informes.stock.index') }}">Stock</a></li>
								<li><a href="{{ route('informes.cuenta-corriente.index') }}">Cuenta Corriente</a></li>
							@endcan
						</ul>
					</li>
					@can('tesoreria.ver')
						<li><a href="{{ route('tesoreria.saldos') }}">
								<div class="menu-icon">
									<svg width="24" height="24" viewBox="0 0 16 16" fill="none"
										xmlns="http://www.w3.org/2000/svg">
										<path
											d="M12.136.326A1.5 1.5 0 0 1 14 1.78V3h.5A1.5 1.5 0 0 1 16 4.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 0 13.5v-9a1.5 1.5 0 0 1 1.432-1.499L12.136.326zM5.562 3H13V1.78a.5.5 0 0 0-.621-.484L5.562 3zM1.5 4a.5.5 0 0 0-.5.5v9a.5.5 0 0 0 .5.5h13a.5.5 0 0 0 .5-.5v-9a.5.5 0 0 0-.5-.5h-13z"
											fill="#6F767E" />
									</svg>
								</div>
								<span class="nav-text">Tesorería</span>
							</a></li>
					@endcan
				</ul>
				<div class="mode-btn d-none align-items-center justify-content-between">
					<div class="d-mode">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<g clip-path="url(#clip0_4_82)">
								<path
									d="M12.025 23.3407L8.62955 20.0479H3.95118V15.3728L0.584229 12L3.95208 8.62704V3.94519H8.6272L12.025 0.572266L15.3731 3.94497H20.055V8.62694L23.4277 12L20.0549 15.3704V20.0488H15.3728L12.025 23.3407ZM12.025 18.3445C13.7812 18.3445 15.2745 17.7251 16.5049 16.4863C17.7353 15.2474 18.3506 13.7439 18.3506 11.9757C18.3506 10.2214 17.7348 8.72844 16.5034 7.49684C15.2719 6.26524 13.7791 5.64944 12.025 5.64944V18.3445ZM12.025 20.9538L14.6609 18.347H18.3513V14.6568L21.0098 12L18.3493 9.33697V5.64874H14.6645L12.025 2.99022L9.34323 5.64874H5.65298V9.33547L2.9962 12L5.65545 14.6592V18.3445H9.31575L12.025 20.9538Z"
									fill="#6F767E" />
							</g>
							<defs>
								<clipPath id="clip0_4_82">
									<rect width="24" height="24" fill="white" />
								</clipPath>
							</defs>
						</svg>
						<span class="ms-2">Dark Mode</span>
					</div>
					<div class="dz-layout light">
						<i class="fas fa-sun sun"></i>
						<i class="fas fa-moon moon"></i>
					</div>
				</div>
			</div>
		</div>
