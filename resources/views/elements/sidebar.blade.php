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
								<li><a href="{{ route('presupuestos.index') }}">Presupuestos</a></li>
							@endcan
							@can('ventas.ver')
								<li><a href="{{ route('ventas.index') }}">Ventas</a></li>
							@endcan
							@can('otros-ingresos.ver')
								<li><a href="{{ route('otros-ingresos.index') }}">Otros Ingresos</a></li>
							@endcan
						</ul>
					</li>
					<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
						<div class="menu-icon">
							<svg width="24" height="24" viewBox="0 0 16 16" fill="none"
								xmlns="http://www.w3.org/2000/svg">
								<path
									d="M2 2a1 1 0 0 1 1-1h4l2 2h4a1 1 0 0 1 1 1v1H2V2zM1 6a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1l-1 8a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1L1 6z"
									fill="#6F767E" />
							</svg>
						</div>
						<span class="nav-text">Egresos</span>
					</a>
						<ul aria-expanded="false">
							@can('compras.ver')
								<li><a href="{{ route('compras.index') }}">Compras</a></li>
							@endcan
							@can('gastos.ver')
								<li><a href="{{ route('gastos.index') }}">Gastos</a></li>
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
								<li><a href="{{ route('clientes.index') }}">Clientes</a></li>
							@endcan
							@can('proveedores.ver')
								<li><a href="{{ route('proveedores.index') }}">Proveedores</a></li>
							@endcan
							@can('productos.ver')
								<li><a href="{{ route('productos.index') }}">Productos &amp; Servicios</a></li>
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
							@endcan
						</ul>
					</li>
					<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
							<div class="menu-icon">
								<svg width="24" height="24" viewBox="0 0 16 16" fill="none"
									xmlns="http://www.w3.org/2000/svg">
									<path
										d="M12.136.326A1.5 1.5 0 0 1 14 1.78V3h.5A1.5 1.5 0 0 1 16 4.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 0 13.5v-9a1.5 1.5 0 0 1 1.432-1.499L12.136.326zM5.562 3H13V1.78a.5.5 0 0 0-.621-.484L5.562 3zM1.5 4a.5.5 0 0 0-.5.5v9a.5.5 0 0 0 .5.5h13a.5.5 0 0 0 .5-.5v-9a.5.5 0 0 0-.5-.5h-13z"
										fill="#6F767E" />
								</svg>
							</div>
							<span class="nav-text">Tesorería</span>
						</a>
						<ul aria-expanded="false">
							@can('tesoreria.ver')
								<li><a href="{{ route('tesoreria.saldos') }}">Saldos</a></li>
								<li><a href="{{ route('tesoreria.movimientos') }}">Movimientos</a></li>
							@endcan
						</ul>
					</li>
					<li><a class="has-arrow " href="javascript:void(0);" aria-expanded="false">
							<div class="menu-icon">
								<svg width="24" height="24" viewBox="0 0 16 16" fill="none"
									xmlns="http://www.w3.org/2000/svg">
									<path
										d="M7.068.727c.243-.97 1.62-.97 1.864 0l.071.286a.96.96 0 0 0 1.622.434l.205-.211c.695-.719 1.888-.03 1.613.931l-.08.284a.96.96 0 0 0 1.187 1.187l.283-.081c.96-.275 1.65.918.931 1.613l-.211.205a.96.96 0 0 0 .434 1.622l.286.071c.97.243.97 1.62 0 1.864l-.286.071a.96.96 0 0 0-.434 1.622l.211.205c.719.695.03 1.888-.931 1.613l-.284-.08a.96.96 0 0 0-1.187 1.187l.081.283c.275.96-.918 1.65-1.613.931l-.205-.211a.96.96 0 0 0-1.622.434l-.071.286c-.243.97-1.62.97-1.864 0l-.071-.286a.96.96 0 0 0-1.622-.434l-.205.211c-.695.719-1.888.03-1.613-.931l.08-.284a.96.96 0 0 0-1.186-1.187l-.284.081c-.96.275-1.65-.918-.931-1.613l.211-.205a.96.96 0 0 0-.434-1.622l-.286-.071c-.97-.243-.97-1.62 0-1.864l.286-.071a.96.96 0 0 0 .434-1.622l-.211-.205c-.719-.695-.03-1.888.931-1.613l.284.08a.96.96 0 0 0 1.187-1.186l-.081-.284c-.275-.96.918-1.65 1.613-.931l.205.211a.96.96 0 0 0 1.622-.434l.071-.286zM12.973 8.5H8.25l-2.834 3.779A4.998 4.998 0 0 0 12.973 8.5zm0-1a4.998 4.998 0 0 0-7.557-3.779l2.834 3.78h4.723zM5.048 3.967c-.03.021-.058.043-.087.065l.087-.065zm-.431.355A4.984 4.984 0 0 0 3.002 8c0 1.455.622 2.765 1.615 3.678L7.375 8 4.617 4.322zm.344 7.646l.087.065-.087-.065z"
										fill="#6F767E" />
								</svg>
							</div>
							<span class="nav-text">Configuración &amp; Ajustes</span>
						</a>
						<ul aria-expanded="false">
							@can('configuracion.usuarios')
								<li><a href="{{ route('configuracion.usuarios.index') }}">Usuarios y Permisos</a></li>
							@endcan
							@can('configuracion.funciones')
								<li><a href="{{ route('configuracion.depositos.index') }}">Depósitos</a></li>
							@endcan
						</ul>
					</li>
				</ul>
				<div class="mode-btn d-flex align-items-center justify-content-between">
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
