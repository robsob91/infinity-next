<!DOCTYPE html>
<html class="no-js">
<head>
	<title data-original="@yield('title', 'Infinity Next')">@yield('title', 'Infinity Next')</title>
	<link rel="shortcut icon" href="{{ asset('static/img/assets/Favicon_Yotsuba.ico') }}"
		data-normal="{{ asset('static/img/assets/Favicon_Yotsuba.ico') }}" data-alert="{{ asset('static/img/assets/Favicon_Yotsuba_new.ico') }}" />
	
	@section('css')
		{!! Minify::stylesheetDir('/static/vendor/')->withFullUrl() !!}
		{!! Minify::stylesheetDir('/static/css/app/')->withFullUrl() !!}
		
		@section('page-css')
			<link id="page-stylesheet" rel="stylesheet" data-instant-track />
		@show
	@show
	
	@yield('js')
	
	@section('meta')
		<meta name="viewport" content="width=device-width" />
	@show
	
	@yield('head')
</head>

<body class="infinity-next responsive @yield('body-class')">
	<div id="page-container">
		@section('header')
		<header class="board-header header-height-1">
			@section('nav-header')
				@include('nav.gnav')
			@show
			
			@section('header-inner')
				<figure class="page-head">
					<img id="logo" src="@yield('header-logo', asset('static/img/logo.png'))" alt="Infinity" />
					<figcaption class="page-details">
						@if (!isset($hideTitles))
							@if (array_key_exists('page-title', View::getSections()))
							<h1 class="page-title">@yield('page-title')</h1>
							@else
							<h1 class="page-title">@yield('title')</h1>
							@endif
						<h2 class="page-desc">@yield('description')</h2>
						@endif
						
						@yield('header-details')
					</figcaption>
				</figure>
				
				@include('widgets.announcement')
			@show
		</header>
		@show
		
		@yield('content')
	</div>
	
	@yield('footer')
</body>
</html>