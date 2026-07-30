<!DOCTYPE html>
<html lang="en">

<head>
@if(isset($baseHref)) <base href="../"> @endif

<title><?php echo !empty(config('dz.pagelevel.'.$CurrentPage.'.title')) ? config('dz.pagelevel.'.$CurrentPage.'.title').' | ' : '' ; echo config('dz.site_level.site_title'); ?></title>

@include('elements.meta', ['CurrentPage' => $CurrentPage])
<link rel="shortcut icon" type="image/png" href="{{ asset(config('dz.site_level.favicon')) }}">

@include('elements.page-css', ['CurrentPage' => $CurrentPage])

</head>

<body class="vh-100">
<script>
	try {
		if (localStorage.getItem('contagram-theme') === 'dark') {
			document.body.setAttribute('data-theme-version', 'dark');
		}
	} catch (e) {}
</script>

	@yield('content')

@include('elements.page-js', ['CurrentPage' => $CurrentPage])

@yield('local-js')
</body>

</html>