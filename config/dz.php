<?php 


return [
	'site_level' => [
		'site_title' => 'Pompei',
		'favicon' => 'images/favicon.png',
		'seo' => [
			'page_title' => 'Dashboard',
			'meta' => [
				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
				['name' => 'author', 'content' => 'DexignZone'],
				['name' => 'robots', 'content' => 'index, follow'],
				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
				['name' => 'format-detection', 'content' => 'telephone=no'],
				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
				['name' => 'twitter:card', 'content' => 'summary_large_image'],
			],
		],
        'support_button' => true,
        'theme_option' => true,
	],
		'global' => [
			'css' => [
			'top' =>[
				'vendor/bootstrap-select/dist/css/bootstrap-select.min.css'
			],
			'bottom' =>[
				'css/style.css',
				'css/contagram-custom.css'
			],
		],
		'js' => [
			'top' => [
                'vendor/global/global.min.js',
				'vendor/bootstrap-select/dist/js/bootstrap-select.min.js',
			],
			'bottom' => [
				'js/deznav-init.js',
				'js/datatables-scroll-fix.js',
				'js/dropdown-escape-scroll.js',
			],
		],
	],
		'pagelevel' => [
		'home' => [
			'title' => 'Inicio',
			'css' => [
				'vendor/toastr/css/toastr.min.css',
				'vendor/bootstrap-daterangepicker/daterangepicker.css',
			],
			'js' => [
				'vendor/toastr/js/toastr.min.js',
				'vendor/moment/moment.min.js',
				'vendor/bootstrap-daterangepicker/daterangepicker.js',
				'vendor/apexchart/apexchart.js',
				'js/custom.js',
			],
		],
		'clientes' => [
			'title' => 'Clientes',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'importacion' => [
			'title' => 'Importar Datos',
			'css' => [
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],

		'proveedores' => [
			'title' => 'Proveedores',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'productos' => [
			'title' => 'Productos & Servicios',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'tesoreria' => [
			'title' => 'Tesorería',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'ventas' => [
			'title' => 'Ventas',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'integraciones' => [
			'title' => 'Integraciones',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'integraciones-ml' => [
			'title' => 'Órdenes MercadoLibre',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'mensajeria' => [
			'title' => 'Mensajería',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'ingresos-mercadolibre' => [
			'title' => 'Mercado Libre',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'ingresos-mercadolibre-vinculaciones' => [
			'title' => 'Vinculación de publicaciones',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'ingresos-tiendanube' => [
			'title' => 'Tiendanube',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'ingresos-tiendanube-vinculaciones' => [
			'title' => 'Vinculación de variantes',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'presupuestos' => [
			'title' => 'Presupuestos',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'compras' => [
			'title' => 'Compras',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'gastos' => [
			'title' => 'Gastos',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'informe-ventas' => [
			'title' => 'Informe de Ventas',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/bootstrap-daterangepicker/daterangepicker.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/moment/moment.min.js',
				'vendor/bootstrap-daterangepicker/daterangepicker.js',
				'js/custom.js',
			],
		],
		'informe-compras' => [
			'title' => 'Informe de Compras',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/bootstrap-daterangepicker/daterangepicker.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/moment/moment.min.js',
				'vendor/bootstrap-daterangepicker/daterangepicker.js',
				'js/custom.js',
			],
		],
		'informe-stock' => [
			'title' => 'Informe de Stock',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
				'vendor/bootstrap-daterangepicker/daterangepicker.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'vendor/moment/moment.min.js',
				'vendor/bootstrap-daterangepicker/daterangepicker.js',
				'js/custom.js',
			],
		],
		'informe-cuenta-corriente' => [
			'title' => 'Cuenta Corriente',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
				'vendor/bootstrap-daterangepicker/daterangepicker.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'vendor/moment/moment.min.js',
				'vendor/bootstrap-daterangepicker/daterangepicker.js',
				'js/custom.js',
			],
		],
		'informe-gastos' => [
			'title' => 'Informe de Gastos',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/bootstrap-daterangepicker/daterangepicker.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/moment/moment.min.js',
				'vendor/bootstrap-daterangepicker/daterangepicker.js',
				'js/custom.js',
			],
		],
		'informe-contador' => [
			'title' => 'Información para el Contador',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/bootstrap-daterangepicker/daterangepicker.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/moment/moment.min.js',
				'vendor/bootstrap-daterangepicker/daterangepicker.js',
				'js/custom.js',
			],
		],
		'informe-ranking' => [
			'title' => 'Informe de Ranking',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/bootstrap-daterangepicker/daterangepicker.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/moment/moment.min.js',
				'vendor/bootstrap-daterangepicker/daterangepicker.js',
				'vendor/apexchart/apexchart.js',
				'js/custom.js',
			],
		],
		'informe-reporte-final' => [
			'title' => 'Reporte Final',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/bootstrap-daterangepicker/daterangepicker.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/moment/moment.min.js',
				'vendor/bootstrap-daterangepicker/daterangepicker.js',
				'js/custom.js',
			],
		],
		'otros-ingresos' => [
			'title' => 'Otros Ingresos',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'abonos' => [
			'title' => 'Abonos',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'puntos-venta' => [
			'title' => 'Puntos de Venta',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'certificados-fiscales' => [
			'title' => 'Certificados Fiscales',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'index' => [
			'title' => 'Home',
			'mainwrapperclass' => 'wallet-open active',
			'sidebar-add-icon' => true,
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/owl-carousel/owl.carousel.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'vendor/webticker/jquery.webticker.min.js',
				'js/dashboard/crypto.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'vendor/owl-carousel/owl.carousel.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]
		],
		'add-blog' => [
			'title' => 'Add Blog',
			'front-menu' => true,
			'css' => [
				'vendor/select2/css/select2.min.css',
				'vendor/bootstrap-datepicker-master/css/bootstrap-datepicker.min.css',
			],
			'js' => [
					'vendor/chart.js/Chart.bundle.min.js',
					'vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js',
					'vendor/ckeditor/ckeditor.js',
					'vendor/select2/js/select2.full.min.js',
					'js/plugins-init/select2-init.js',
					'js/dashboard/cms.js',
					'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]
		],
		'add-categary' =>[
			'title' => 'Add Categary',
			'mainwrapperclass' => 'overflow-visible',
			'css' => [
				'vendor/select2/css/select2.min.css',
				'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@48,400,0,0',
			],
			'js' => [
				'vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js',
				'vendor/ckeditor/ckeditor.js',
				'vendor/select2/js/select2.full.min.js',
				'js/plugins-init/select2-init.js',
				'js/dashboard/cms.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'add-email' =>[
			'title' => 'Add Email',
			'front-menu' => true,
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'js/dashboard/cms.js',
				'vendor/ckeditor/ckeditor.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],		
		'add-product' =>[
			'title' => 'Add Product',
			'mainwrapperclass' => 'overflow-visible',
			'css' => [
				'vendor/select2/css/select2.min.css',
				'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@48,400,0,0',
			],
			'js' => [
				'vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js',
				'vendor/dropzone/dist/dropzone.js',
				'vendor/ckeditor/ckeditor.js',
				'vendor/select2/js/select2.full.min.js',
				'js/plugins-init/select2-init.js',
				'js/dashboard/cms.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'analytics' =>[
			'title' => 'Analytics',
			'mainwrapperclass' => 'wallet-open active',
			'sidebar-add-icon' => true,
			'css' => [
				'/vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/jvmap/jquery-jvectormap.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'vendor/jqvmap/js/jquery.vmap.min.js',
				'vendor/jqvmap/js/jquery.vmap.world.js',
				'vendor/jqvmap/js/jquery.vmap.usa.js',
				'js/dashboard/analytics.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'app-calender' =>[
			'title' => 'Add Calender',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/fullcalendar/css/main.min.css',
			],
			'js' => [
				'vendor/moment/moment.min.js',
				'vendor/fullcalendar/js/main.min.js',
				'js/plugins-init/fullcalendar-init.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'app-profile-1' =>[
			'title' => 'App Profile ',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/lightgallery/css/lightgallery.min.css',
			],
			'js' => [
				'vendor/jquery-nice-select/js/jquery.nice-select.min.js',
				'vendor/lightgallery/js/lightgallery-all.min.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'app-profile-2' =>[
			'title' => 'App Profile',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/lightgallery/css/lightgallery.min.css',
			],
			'js' => [
				'vendor/lightgallery/js/lightgallery-all.min.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'app-profile' =>[
			'title' => 'App Profile',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/lightgallery/css/lightgallery.min.css',
			],
			'js' => [
				'vendor/jquery-nice-select/js/jquery.nice-select.min.js',
				'vendor/lightgallery/js/lightgallery-all.min.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'blog-category' =>[
			'title' => 'Blog Categary',
			'front-menu' => true,
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
				'js/dashboard/cms.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'category-table' =>[
			'title' => 'Category Table',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'chart-chartist' =>[
			'title' => 'Chart Chartist',
			'front-menu' => true,
			'css' => [
				'vendor/chartist/css/chartist.min.css',
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
			    'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
			    'vendor/chartist/js/chartist.min.js',
			    'vendor/chartist-plugin-tooltips/js/chartist-plugin-tooltip.min.js',
			    'js/plugins-init/chartist-init.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'chart-chartjs' =>[
			'title' => 'Chart Chartjs',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
			    'vendor/chart.js/Chart.bundle.min.js',
			    'js/plugins-init/chartjs-init.js',	
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'chart-flot' =>[
			'title' => 'Chart Flot',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
			    'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
			    'vendor/flot/jquery.flot.js',
			    'vendor/flot/jquery.flot.pie.js',
			    'vendor/flot/jquery.flot.resize.js',
			    'vendor/flot-spline/jquery.flot.spline.min.js',
			    'js/plugins-init/flot-init.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'chart-morris' =>[
			'title' => 'Chart Morris',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
			    'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',  
			    'vendor/flot/jquery.flot.js',
			    'vendor/flot/jquery.flot.pie.js',
			    'vendor/flot/jquery.flot.resize.js',
			    'vendor/flot-spline/jquery.flot.spline.min.js',
			    'js/plugins-init/flot-init.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'chart-peity' =>[
			'title' => 'Chart Peity',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/peity/jquery.peity.min.js',
				'js/plugins-init/piety-init.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'chart-sparkline' =>[
			'title' => 'Chart Sparkline',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/jquery-sparkline/jquery.sparkline.min.js',
				'js/plugins-init/sparkline-init.js',
				'vendor/svganimation/vivus.min.js',
				'vendor/svganimation/svg.animation.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'chat' =>[
			'title' => 'Chat',
			'front-menu' => true,
			'css' => [
				'vendor/dropzone/dist/dropzone.css',
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/14.6.4/nouislider.min.css',
			],
			'js' => [
				'vendor/jquery-nice-select/js/jquery.nice-select.min.js',
				'vendor/dropzone/dist/dropzone.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'cms-blog' =>[
			'title' => 'Cms Blog',
			'front-menu' => true,
			'css' => [
				'vendor/bootstrap-datepicker-master/css/bootstrap-datepicker.min.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js',
				'js/dashboard/cms.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'content-add' =>[
			'title' => 'Content Add',
			'front-menu' => true,
			'css' => [
				'vendor/select2/css/select2.min.css',
				'vendor/bootstrap-datepicker-master/css/bootstrap-datepicker.min.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js',
				'vendor/ckeditor/ckeditor.js',
				'vendor/select2/js/select2.full.min.js',
				'js/plugins-init/select2-init.js',
				'js/dashboard/cms.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'content' =>[
			'title' => 'content',
			'front-menu' => true,
			'css' => [
				'vendor/bootstrap-select/dist/css/bootstrap-select.min.css',
			],
			'js' => [
				'vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js',
				'vendor/peity/jquery.peity.min.js',
				'js/dashboard/cms.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'course' =>[
			'title' => 'Course',
			'mainwrapperclass' => 'wallet-open',
			'css' => [
				'vendor/bootstrap-datetimepicker/css/bootstrap-datetimepicker.min.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'vendor/bootstrap-datetimepicker/js/moment.js',
				'vendor/bootstrap-datetimepicker/js/bootstrap-datetimepicker.min.js',
				'js/dashboard/course.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'crm' => [
			'title' => 'CRM',
			'mainwrapperclass' => 'wallet-open',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/owl-carousel/owl.carousel.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'vendor/webticker/jquery.webticker.min.js',
				'js/dashboard/crm.js',
				'vendor/draggable/draggable.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'vendor/owl-carousel/owl.carousel.js',
				'js/custom.js',
			],	
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]		
		],
		'crypto' =>[
			'title' => 'Crypto',
			'mainwrapperclass' => 'wallet-open active',
			'sidebar-add-icon' => true,
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/owl-carousel/owl.carousel.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'vendor/webticker/jquery.webticker.min.js',
				'js/dashboard/crypto.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'vendor/owl-carousel/owl.carousel.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ecom-checkout' =>[
			'title' => 'Checkout',
			'js' => [
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ecom-customers' =>[
			'title' => 'Customers',
			'js' => [
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ecom-invoice' =>[
			'title' => 'Invoice',
			'js' => [
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ecom-product-detail' =>[
			'title' => 'Product Detail',
			'css' => [
				'vendor/star-rating/star-rating-svg.css',
				'vendor/lightgallery/dist/css/lightgallery.css',
				'vendor/lightgallery/dist/css/lg-thumbnail.css',
				'vendor/swiper/css/swiper-bundle.min.css',
			],
			'js' => [
				'vendor/lightgallery/dist/lightgallery.min.js',
				'vendor/lightgallery/dist/plugins/thumbnail/lg-thumbnail.min.js',
				'vendor/lightgallery/dist/plugins/zoom/lg-zoom.min.js',
				'vendor/star-rating/jquery.star-rating-svg.js',
				'vendor/swiper/js/swiper-bundle.min.js',
				'vendor/countdown/jquery.countdown.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ecom-product-grid' =>[
			'title' => 'Product Grid',
			'mainwrapperclass' => 'overflow-visible',
			'css' => [
				'vendor/nouislider/nouislider.min.css',
			],
			'js' => [
				'vendor/nouislider/nouislider.min.js',
				'vendor/wnumb/wNumb.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ecom-product-list' =>[
			'title' => 'Product List',
			'mainwrapperclass' => 'overflow-visible',
			'css' => [
				'vendor/star-rating/star-rating-svg.css',
				'vendor/nouislider/nouislider.min.css',
			],
			'js' => [
				'vendor/nouislider/nouislider.min.js',
				'vendor/wnumb/wNumb.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ecom-product-order' =>[
			'title' => 'Product Order',
			'js' => [
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ecommerce' =>[
			'title' => 'Ecommerce',
			'mainwrapperclass' => 'wallet-open',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/swiper/css/swiper-bundle.min.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'js/dashboard/ecommerce.js',
				'vendor/draggable/draggable.js',
				'vendor/swiper/js/swiper-bundle.min.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.html5.min.js',
				'vendor/datatables/js/jszip.min.js',
				'js/plugins-init/datatables.init.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'edit-categary' =>[
			'title' => 'Edit Categary',
			'mainwrapperclass' => 'overflow-visible',
			'css' => [
				'vendor/select2/css/select2.min.css',
				'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@48,400,0,0',
			],
			'js' => [
				'vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js',
				'vendor/ckeditor/ckeditor.js',
				'vendor/select2/js/select2.full.min.js',
				'js/plugins-init/select2-init.js',
				'js/dashboard/cms.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'edit-product' =>[
			'title' => 'Edit Product',
			'mainwrapperclass' => 'overflow-visible',
			'css' => [
				'vendor/select2/css/select2.min.css',
				'vendor/dropzone/dist/dropzone.css',
				'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@48,400,0,0',
			],
			'js' => [
				'vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js',
				'vendor/dropzone/dist/dropzone.js',
				'vendor/ckeditor/ckeditor.js',
				'vendor/select2/js/select2.full.min.js',
				'js/plugins-init/select2-init.js',
				'js/dashboard/cms.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'edit-profile' =>[
			'title' => 'Edit Profile',
			'front-menu' => true,
			'css' => [
				'vendor/bootstrap-datepicker-master/css/bootstrap-datepicker.min.css',
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'vendor/jquery-nice-select/js/jquery.nice-select.min.js',
				'vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'email-compose' =>[
			'title' => 'Email Compose',
			'front-menu' => true,
			'css' => [
				'vendor/dropzone/dist/dropzone.css',
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/14.6.4/nouislider.min.css',
			],
			'js' => [
				'vendor/jquery-nice-select/js/jquery.nice-select.min.js',
				'vendor/dropzone/dist/dropzone.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'email-inbox' =>[
			'title' => 'Email Inbox',
			'front-menu' => true,
			'css' => [
				'vendor/dropzone/dist/dropzone.css',
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/14.6.4/nouislider.min.css',
			],
			'js' => [
				'vendor/dropzone/dist/dropzone.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'email-read' =>[
			'title' => 'Email Read',
			'front-menu' => true,
			'css' => [
				'vendor/bootstrap-select/dist/css/bootstrap-select.min.css',
				'vendor/dropzone/dist/dropzone.css',
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'vendor/jquery-nice-select/js/jquery.nice-select.min.js',
				'vendor/dropzone/dist/dropzone.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'email-template' =>[
			'title' => 'Email Template',
			'front-menu' => true,
			'css' => [
				'vendor/bootstrap-datepicker-master/css/bootstrap-datepicker.min.css',
			],
			'js' => [
				'vendor/bootstrap-datepicker-master/js/bootstrap-datepicker.min.js',
				'js/dashboard/cms.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],	
		'empty-page' =>[
			'title' => 'Empty Page',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'error-404' =>[
			'title' => 'Error 404',
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'finance' =>[
			'title' => 'Finance',			
			'mainwrapperclass' => 'wallet-open',
			'sidebar-add-icon' => true,
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/owl-carousel/owl.carousel.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'vendor/draggable/draggable.js',
				'js/dashboard/finance.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.html5.min.js',
				'vendor/datatables/js/jszip.min.js',
				'js/plugins-init/datatables.init.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'form-ckeditor' =>[
			'title' => 'Form Ckeditor',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'vendor/ckeditor/ckeditor.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'form-element' =>[
			'title' => 'Form Element',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'form-pickers' =>[
			'title' => 'Form Pickers',
			'front-menu' => true,
			'css' => [
			    'vendor/bootstrap-daterangepicker/daterangepicker.css',
			    'vendor/clockpicker/css/bootstrap-clockpicker.min.css',
			    'vendor/jquery-asColorPicker/css/asColorPicker.min.css',
			    'vendor/bootstrap-material-datetimepicker/css/bootstrap-material-datetimepicker.css',
			    'vendor/pickadate/themes/default.css',
			    'vendor/pickadate/themes/default.date.css',
				'https://fonts.googleapis.com/icon?family=Material+Icons',
			],
			'js' => [
			    'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
			    'vendor/moment/moment.min.js',
			    'vendor/bootstrap-daterangepicker/daterangepicker.js',
			    'vendor/clockpicker/js/bootstrap-clockpicker.min.js',
			    'vendor/jquery-asColor/jquery-asColor.min.js',
			    'vendor/jquery-asGradient/jquery-asGradient.min.js',
			    'vendor/jquery-asColorPicker/js/jquery-asColorPicker.min.js',
			    'vendor/bootstrap-material-datetimepicker/js/bootstrap-material-datetimepicker.js',
			    'vendor/pickadate/picker.js',
			    'vendor/pickadate/picker.time.js',
			    'vendor/pickadate/picker.date.js',
			    'js/plugins-init/bs-daterange-picker-init.js',
			    'js/plugins-init/clock-picker-init.js',
			    'js/plugins-init/jquery-asColorPicker.init.js',
			    'js/plugins-init/material-date-picker-init.js',
			    'js/plugins-init/pickadate-init.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'form-validation' =>[
			'title' => 'Form Validation',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'form-wizard' =>[
			'title' => 'Form Wizard',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/jquery-smartwizard/dist/css/smart_wizard.min.css',
			],
			'js' => [
			    'vendor/jquery-steps/build/jquery.steps.min.js',
			    'vendor/jquery-validation/jquery.validate.min.js',
			    'js/plugins-init/jquery.validate-init.js',
				'vendor/jquery-smartwizard/dist/js/jquery.smartWizard.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'index-2' =>[
			'title' => 'Home',			
			'mainwrapperclass' => 'wallet-open active',
			'sidebar-add-icon' => true,
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/owl-carousel/owl.carousel.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'vendor/webticker/jquery.webticker.min.js',
				'js/dashboard/crypto.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'vendor/owl-carousel/owl.carousel.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'index-3' =>[
			'title' => 'Home',
			'front-menu' => true,
			'css' => [
				'vendor/swiper/css/swiper-bundle.min.css',
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/14.6.4/nouislider.min.css',
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/jvmap/jquery-jvectormap.css',
				'https://cdn.datatables.net/buttons/1.6.4/css/buttons.dataTables.min.css',
				'vendor/bootstrap-datetimepicker/css/bootstrap-datetimepicker.min.css',
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'js/dashboard/dashboard-1.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'vendor/svganimation/vivus.min.js',
			    'vendor/svganimation/svg.animation.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'index-4' =>[
			'title' => 'Home',
			'front-menu' => true,
			'css' => [
				'vendor/swiper/css/swiper-bundle.min.css',
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/14.6.4/nouislider.min.css',
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/jvmap/jquery-jvectormap.css',
				'https://cdn.datatables.net/buttons/1.6.4/css/buttons.dataTables.min.css',
				'vendor/bootstrap-datetimepicker/css/bootstrap-datetimepicker.min.css',
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'js/dashboard/dashboard-1.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'vendor/svganimation/vivus.min.js',
			    'vendor/svganimation/svg.animation.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'map-jqvmap' =>[
			'title' => 'Map',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/jqvmap/css/jqvmap.min.css',
			],
			'js' => [
			    'vendor/jqvmap/js/jquery.vmap.min.js',
			    'vendor/jqvmap/js/jquery.vmap.world.js',
			    'vendor/jqvmap/js/jquery.vmap.usa.js',
			    'js/plugins-init/jqvmap-init.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'medical' =>[
			'title' => 'Medical',
			'mainwrapperclass' => 'wallet-open',
			'css' => [
				'vendor/bootstrap-datetimepicker/css/bootstrap-datetimepicker.min.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'vendor/bootstrap-datetimepicker/js/moment.js',
				'vendor/bootstrap-datetimepicker/js/bootstrap-datetimepicker.min.js',
				'vendor/draggable/draggable.js',
				'js/dashboard/medical.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'menu' =>[
			'title' => 'Menu',
			'front-menu' => true,
			'css' => [
				'vendor/bootstrap-select/dist/css/bootstrap-select.min.css',
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/nestable2/css/jquery.nestable.min.css',
			],
			'js' => [
				'js/dashboard/cms.js',
				'vendor/nestable2/js/jquery.nestable.min.js',
				'js/plugins-init/nestable-init.js',
				'vendor/ckeditor/ckeditor.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'my-project' =>[
			'title' => 'My Project',
			'front-menu' => true,
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/peity/jquery.peity.min.js',
				'js/dashboard/dashboard-1.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'configuracion-index' => [
			'title' => 'Configuración & Ajustes',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'configuracion-perfil' => [
			'title' => 'Mi Perfil',
			'css' => [
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'configuracion-usuarios' => [
			'title' => 'Usuarios y Permisos',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'configuracion-roles' => [
			'title' => 'Roles y Permisos',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'configuracion-facturacion-electronica' => [
			'title' => 'Facturación Electrónica',
			'css' => [
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'configuracion-mi-perfil' => [
			'title' => 'Empresa',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'configuracion-depositos' => [
			'title' => 'Depósitos',
			'css' => [
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'configuracion-funciones-avanzadas' => [
			'title' => 'Funciones Avanzadas',
			'css' => [
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'configuracion-mercadolibre' => [
			'title' => 'Mercado Libre',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'vendor/select2/js/select2.full.min.js',
				'js/custom.js',
			],
		],
		'configuracion-mercadolibre-bot' => [
			'title' => 'Bot de Mercado Libre',
			'css' => [
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'configuracion-tiendanube' => [
			'title' => 'Tiendanube',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/datatables/css/buttons.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.colVis.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'configuracion-importar' => [
			'title' => 'Importar Datos',
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'configuracion-ajustes' => [
			'title' => 'Ajustes',
			'css' => [
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
				'vendor/toastr/js/toastr.min.js',
				'js/custom.js',
			],
		],
		'page-error-400' =>[
			'title' => 'Error 404',
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'page-error-403' =>[
			'title' => 'Error 403',
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'page-error-404' =>[
			'title' => 'Error 404',
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'page-error-500' =>[
			'title' => 'Error 500',
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'page-error-503' =>[
			'title' => 'Error 503',
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'page-forgot-password' =>[
			'title' => 'Forgot Password',
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'page-lock-screen' =>[
			'title' => 'Lock Screen',
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'page-login' =>[
			'title' => 'Login',
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'page-register' =>[
			'title' => 'Resgister',
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'post-details' =>[
			'title' => 'Post Details',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/lightgallery/css/lightgallery.min.css',
			],
			'js' => [
				'vendor/lightgallery/js/lightgallery-all.min.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'product-table' =>[
			'title' => 'Product Table',			
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'table-bootstrap-basic' =>[
			'title' => 'Table',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'table-datatable-basic' =>[
			'title' => 'Datatable',
			'front-menu' => true,
			'css' => [
				'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
				'vendor/chart.js/Chart.bundle.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/datatables/js/jquery.dataTables.min.js',
				'js/plugins-init/datatables.init.js',
				'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'uc-lightgallery' =>[
			'title' => 'Lightgallery',
			'front-menu' => true,
			'css' => [
				'vendor/lightgallery/css/lightgallery.min.css',
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'vendor/lightgallery/js/lightgallery-all.min.js',
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'uc-nestable' =>[
			'title' => 'Nestable',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/nestable2/css/jquery.nestable.min.css',
			],
			'js' => [
			    'vendor/nestable2/js/jquery.nestable.min.js',
			    'js/plugins-init/nestable-init.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'uc-noui-slider' =>[
			'title' => 'Noui Slider',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/nouislider/nouislider.min.css',
			],
			'js' => [
			    'vendor/nouislider/nouislider.min.js',
			    'vendor/wnumb/wNumb.js',
			    'js/plugins-init/nouislider-init.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'uc-select2' =>[
			'title' => 'Select',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/select2/css/select2.min.css',
			],
			'js' => [
			    'vendor/select2/js/select2.full.min.js',
			    'js/plugins-init/select2-init.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'uc-sweetalert' =>[
			'title' => 'Sweetalert',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/sweetalert2/dist/sweetalert2.min.css',
			],
			'js' => [
			    'vendor/sweetalert2/dist/sweetalert2.min.js',
			    'js/plugins-init/sweetalert.init.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'uc-toastr' =>[
			'title' => 'Toastr',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/toastr/css/toastr.min.css',
			],
			'js' => [
			    'vendor/toastr/js/toastr.min.js',
			    'js/plugins-init/toastr-init.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ui-accordion' =>[
			'title' => 'Accordion',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ui-alert' =>[
			'title' => 'Alert',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ui-badge' =>[
			'title' => 'Badge',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ui-breadcrumb' =>[
			'title' => 'Breadcrumb',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'ui-button-group' =>[
			'title' => 'Button Group',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
        'ui-button' =>[
            'title' => 'Button',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-card' =>[
            'title' => 'Card',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
		'ui-carousel' =>[
			'title' => 'Carousel',
			'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],        
        'ui-colors' =>[
            'title' => 'Colors',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],                
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-dropdown' =>[
            'title' => 'Dropdown',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-grid' =>[
            'title' => 'Grid',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-list-group' =>[
            'title' => 'List Group',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-media-object' =>[
            'title' => 'Media Object',
            'js' => [
                'js/custom.js',
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-modal' =>[
            'title' => 'Modal',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-navbar' =>[
            'title' => 'Navbar',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-object-fit' =>[
            'title' => 'Object Fit',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-offcanvas' =>[
            'title' => 'Offcanvas',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-pagination' =>[
            'title' => 'Pagination',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],        
        'ui-placeholder' =>[
            'title' => 'Placeholder',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-popover' =>[
            'title' => 'Popover',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-progressbar' =>[
            'title' => 'Progressbar',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-range-slider' =>[
            'title' => 'Range Slider',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-scrollspy' =>[
            'title' => 'Scrollspy',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-spinners' =>[
            'title' => 'Spinners',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-tab' =>[
            'title' => 'Tab',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-toasts' =>[
            'title' => 'Toasts',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
				'bottom' =>[
					'js/highlight.min.js'
				],
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
        'ui-typography' =>[
            'title' => 'Typography',
            'front-menu' => true,
			'css' => [
                'https://fonts.googleapis.com/css2?family=Material+Icons',
            ],
            'js' => [
                'js/custom.js',
            ],
            'seo' => [
                'page_title' => 'Dashboard',
                'meta' => [
                    ['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
                    ['name' => 'author', 'content' => 'DexignZone'],
                    ['name' => 'robots', 'content' => 'index, follow'],
                    ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
                    ['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'format-detection', 'content' => 'telephone=no'],
                    ['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
                    ['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
                    ['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
                    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
                ]
            ]                   
        ],
		'widget-basic' =>[
			'title' => 'Widget Basic',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/chartist/css/chartist.min.css',
			],
			'js' => [
			    'vendor/chart.js/Chart.bundle.min.js',
			    'vendor/apexchart/apexchart.js',
			    'vendor/chartist/js/chartist.min.js',
			    'vendor/chartist-plugin-tooltips/js/chartist-plugin-tooltip.min.js',
			    'vendor/flot/jquery.flot.js',
			    'vendor/flot/jquery.flot.pie.js',
			    'vendor/flot/jquery.flot.resize.js',
			    'vendor/flot-spline/jquery.flot.spline.min.js',
			    'vendor/jquery-sparkline/jquery.sparkline.min.js',
			    'js/plugins-init/sparkline-init.js',
			    'vendor/peity/jquery.peity.min.js',
			    'js/plugins-init/piety-init.js',
			    'js/plugins-init/widgets-script-init.js',
			    'js/custom.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'activity' =>[
			'title' => 'Activity',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
			    'vendor/apexchart/apexchart.js',
			    'vendor/tagify/dist/tagify.js',
			    'vendor/draggable/draggable.js',
			    'js/dashboard/profile.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'api-keys' =>[
			'title' => 'Api Keys',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
				'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.html5.min.js',
				'vendor/datatables/js/jszip.min.js',
				'vendor/apexchart/apexchart.js',
				'vendor/tagify/dist/tagify.js',
				'js/dashboard/profile.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'billing' =>[
			'title' => 'Billing',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
				'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
			    'vendor/datatables/js/jquery.dataTables.min.js',
			    'vendor/datatables/js/dataTables.buttons.min.js',
			    'vendor/datatables/js/buttons.html5.min.js',
			    'vendor/datatables/js/jszip.min.js',
			    'vendor/apexchart/apexchart.js',
			    'vendor/tagify/dist/tagify.js',
			    'js/dashboard/profile.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'logs' =>[
			'title' => 'Logs',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
				'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
			    'vendor/datatables/js/jquery.dataTables.min.js',
			    'vendor/datatables/js/dataTables.buttons.min.js',
			    'vendor/datatables/js/buttons.html5.min.js',
			    'vendor/datatables/js/jszip.min.js',
			    'vendor/apexchart/apexchart.js',
			    'vendor/tagify/dist/tagify.js',
			    'js/dashboard/profile.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'overview' =>[
			'title' => 'Overview',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
			    'vendor/datatables/js/jquery.dataTables.min.js',
			    'vendor/datatables/js/dataTables.buttons.min.js',
			    'vendor/datatables/js/buttons.html5.min.js',
			    'vendor/datatables/js/jszip.min.js',
			    'vendor/apexchart/apexchart.js',
			    'vendor/tagify/dist/tagify.js',
			    'js/dashboard/profile.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'referrals' =>[
			'title' => 'Referrals',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
			    'vendor/datatables/js/jquery.dataTables.min.js',
			    'vendor/datatables/js/dataTables.buttons.min.js',
			    'vendor/datatables/js/buttons.html5.min.js',
			    'vendor/datatables/js/jszip.min.js',
			    'vendor/apexchart/apexchart.js',
			    'vendor/tagify/dist/tagify.js',
			    'js/dashboard/profile.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'security' =>[
			'title' => 'Security',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
			    'vendor/tagify/dist/tagify.css',
			    'vendor/owl-carousel/owl.carousel.css',
			    'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
			    'vendor/datatables/js/jquery.dataTables.min.js',
			    'vendor/datatables/js/dataTables.buttons.min.js',
			    'vendor/datatables/js/buttons.html5.min.js',
			    'vendor/datatables/js/jszip.min.js',
			    'vendor/owl-carousel/owl.carousel.js',
			    'vendor/apexchart/apexchart.js',
			    'vendor/tagify/dist/tagify.js',
			    'js/dashboard/profile.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'settings' =>[
			'title' => 'Settings',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
			    'vendor/apexchart/apexchart.js',
			    'vendor/tagify/dist/tagify.js',
			    'js/dashboard/profile.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'statements' =>[
			'title' => 'Statements',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
			    'vendor/datatables/js/jquery.dataTables.min.js',
			    'vendor/datatables/js/dataTables.buttons.min.js',
			    'vendor/datatables/js/buttons.html5.min.js',
			    'vendor/datatables/js/jszip.min.js',
			    'vendor/apexchart/apexchart.js',
			    'vendor/tagify/dist/tagify.js',
			    'js/dashboard/profile.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'auto-write' =>[
			'title' => 'Auto Write',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
			],
			'js' => [
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'chatbot' =>[
			'title' => 'Chatbot',
			'front-menu' => true,
			'css' => [
			    'https://fonts.googleapis.com/css2?family=Material+Icons',
			    'vendor/nouislider/nouislider.min.css',
			    'vendor/clockpicker/css/bootstrap-clockpicker.min.css',
			    'vendor/jquery-ascolorpicker/css/asColorPicker.min.css',
			    'vendor/bootstrap-material-datetimepicker/css/bootstrap-material-datetimepicker.css',
			],
			'js' => [
			    'vendor/nouislider/nouislider.min.js',
			    'vendor/wnumb/wNumb.js',
			    'vendor/jquery-ascolor/jquery-ascolor.min.js',
			    'vendor/jquery-asgradient/jquery-asgradient.min.js',
			    'vendor/jquery-ascolorpicker/js/jquery-ascolorpicker.min.js',
			    'js/plugins-init/jquery-ascolorpicker.init.js',
			    'js/custom.min.js',

			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'fine-tune-models' =>[
			'title' => 'Fine Tune Models',
			'front-menu' => true,
			'css' => [
			    'https://fonts.googleapis.com/css2?family=Material+Icons',
			    'vendor/datatables/css/jquery.dataTables.min.css',
			    'https://cdn.datatables.net/buttons/1.6.4/css/buttons.dataTables.min.css',
			],
			'js' => [
			    'vendor/datatables/js/jquery.dataTables.min.js',
			    'vendor/datatables/js/dataTables.buttons.min.js',
			    'vendor/datatables/js/buttons.html5.min.js',
			    'vendor/datatables/js/jszip.min.js',
			    'js/plugins-init/datatables.init.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'import' =>[
			'title' => 'Import',
			'front-menu' => true,
			'css' => [
			    'vendor/dropzone/dist/dropzone.css',
			    'https://fonts.googleapis.com/css2?family=Material+Icons',
			    'vendor/bootstrap-select/dist/css/bootstrap-select.min.css',
			],
			'js' => [
				'vendor/dropzone/dist/dropzone.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'prompt' =>[
			'title' => 'Prompt',
			'front-menu' => true,
			'css' => [
				'https://fonts.googleapis.com/css2?family=Material+Icons',
				'vendor/nouislider/nouislider.min.css',
			],
			'js' => [
			    'vendor/nouislider/nouislider.min.js',
			    'vendor/wnumb/wNumb.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'repurpose' =>[
			'title' => 'Repurpose',
			'front-menu' => true,
			'css' => [
			    'https://fonts.googleapis.com/css2?family=Material+Icons',
			    'vendor/datatables/css/jquery.dataTables.min.css',
			    'https://cdn.datatables.net/buttons/1.6.4/css/buttons.dataTables.min.css',
			],
			'js' => [
			    'vendor/datatables/js/jquery.dataTables.min.js',
			    'vendor/datatables/js/dataTables.buttons.min.js',
			    'vendor/datatables/js/buttons.html5.min.js',
			    'vendor/datatables/js/jszip.min.js',
			    'js/plugins-init/datatables.init.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'rss' =>[
			'title' => 'Rss',
			'front-menu' => true,
			'css' => [
			    'https://fonts.googleapis.com/css2?family=Material+Icons',
			    'vendor/datatables/css/jquery.dataTables.min.css',
			    'https://cdn.datatables.net/buttons/1.6.4/css/buttons.dataTables.min.css',
			],
			'js' => [
			    'vendor/datatables/js/jquery.dataTables.min.js',
			    'vendor/datatables/js/dataTables.buttons.min.js',
			    'vendor/datatables/js/buttons.html5.min.js',
			    'vendor/datatables/js/jszip.min.js',
			    'js/plugins-init/datatables.init.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'scheduled' =>[
			'title' => 'Scheduled',
			'front-menu' => true,
			'css' => [
			    'vendor/swiper/css/swiper-bundle.min.css',
			    'vendor/datatables/css/jquery.dataTables.min.css',
			    'https://cdn.datatables.net/buttons/1.6.4/css/buttons.dataTables.min.css',
			    'vendor/bootstrap-datetimepicker/css/bootstrap-datetimepicker.min.css',
			    'vendor/tagify/dist/tagify.css',
			],
			'js' => [
			    'vendor/chart.js/Chart.bundle.min.js',
			    'vendor/apexchart/apexchart.js',
			    'vendor/datatables/js/jquery.dataTables.min.js',
			    'vendor/datatables/js/dataTables.buttons.min.js',
			    'vendor/datatables/js/buttons.html5.min.js',
			    'vendor/datatables/js/jszip.min.js',
			    'js/plugins-init/datatables.init.js',
			    'vendor/tagify/dist/tagify.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'setting' =>[
			'title' => 'Setting',
			'front-menu' => true,
			'css' => [
			    'https://fonts.googleapis.com/css2?family=Material+Icons',
			    'vendor/nouislider/nouislider.min.css',
			    'vendor/clockpicker/css/bootstrap-clockpicker.min.css',
			    'vendor/jquery-ascolorpicker/css/asColorPicker.min.css',
			    'vendor/tagify/dist/tagify.css',
			    'vendor/bootstrap-material-datetimepicker/css/bootstrap-material-datetimepicker.css',
			],
			'js' => [
			    'vendor/nouislider/nouislider.min.js',
			    'vendor/wnumb/wNumb.js',
			    'vendor/jquery-ascolor/jquery-ascolor.min.js',
			    'vendor/jquery-asgradient/jquery-asgradient.min.js',
			    'vendor/jquery-ascolorpicker/js/jquery-ascolorpicker.min.js',
			    'js/plugins-init/jquery-ascolorpicker.init.js',
			    'vendor/tagify/dist/tagify.js',
			    'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],		
		'activity-profile' =>[
			'title' => 'Activity',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
				'vendor/draggable/draggable.js',
				'vendor/tagify/dist/tagify.js',
				'vendor/apexchart/apexchart.js',
				'js/dashboard/profile.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'campaigns' =>[
			'title' => 'Campaigns',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
				'vendor/apexchart/apexchart.js',
				'vendor/tagify/dist/tagify.js',
				'js/dashboard/profile.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'documents' =>[
			'title' => 'Documents',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
				'vendor/apexchart/apexchart.js',
				'vendor/tagify/dist/tagify.js',
				'js/dashboard/profile.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'followers' =>[
			'title' => 'Followers',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
				'vendor/apexchart/apexchart.js',
				'vendor/tagify/dist/tagify.js',
				'js/dashboard/profile.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'overview-profile' =>[
			'title' => 'Overview',			
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
				'vendor/lightgallery/css/lightgallery.min.css',
			],
			'js' => [
				'vendor/apexchart/apexchart.js',
				'vendor/draggable/draggable.js',
				'vendor/lightgallery/js/lightgallery-all.min.js',
				'vendor/tagify/dist/tagify.js',
				'js/dashboard/profile.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'projects-details' =>[
			'title' => 'Projects Details',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/owl-carousel/owl.carousel.css',
				'vendor/datatables/css/jquery.dataTables.min.css',
			],
			'js' => [
				'vendor/datatables/js/jquery.dataTables.min.js',
				'vendor/datatables/js/dataTables.buttons.min.js',
				'vendor/datatables/js/buttons.html5.min.js',
				'vendor/datatables/js/jszip.min.js',
				'vendor/owl-carousel/owl.carousel.js',
				'vendor/apexchart/apexchart.js',
				'vendor/tagify/dist/tagify.js',
				'js/dashboard/profile.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
		'projects' =>[
			'title' => 'Projects',
			'mainwrapperclass' => 'show',
			'front-menu' => true,
			'css' => [
				'vendor/tagify/dist/tagify.css',
			],
			'js' => [
				'vendor/apexchart/apexchart.js',
				'vendor/tagify/dist/tagify.js',
				'js/dashboard/profile.js',
				'js/custom.min.js',
			],
			'seo' => [
    			'page_title' => 'Dashboard',
    			'meta' => [
    				['name' => 'keywords', 'content' => 'NexaDash is a modern admin dashboard template built with Bootstrap 5. Featuring a clean and responsive design, it provides a range of customizable components and tools to efficiently manage and oversee various administrative tasks.'],
    				['name' => 'author', 'content' => 'DexignZone'],
    				['name' => 'robots', 'content' => 'index, follow'],
    				['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, minimal-ui, viewport-fit=cover'],
    				['name' => 'description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['property' => 'og:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['property' => 'og:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'format-detection', 'content' => 'telephone=no'],
    				['name' => 'twitter:title', 'content' => 'NexaDash Admin Dashboard Bootstrap 5 Template'],
    				['name' => 'twitter:description', 'content' => 'NexaDash Bootstrap 5 template, admin dashboard design, modern admin panel, responsive admin template, Bootstrap 5 admin components, administrative task management, customizable dashboard design, NexaDash features, Bootstrap admin template, user-friendly admin interface'],
    				['name' => 'twitter:image', 'content' => 'https://nexadash.dexignzone.com/xhtml/social-image.png'],
    				['name' => 'twitter:card', 'content' => 'summary_large_image'],
    			]
    		]					
		],
	]
];
 ?>