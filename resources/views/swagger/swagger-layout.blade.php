<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Laravel - Swagger UI</title>
  {{-- Include Tailwind CSS --}}
  @vite('resources/css/app.css')
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@4/swagger-ui.css" />

</head>
<body class="bg-gray-50">
  <!-- Navbar -->
<nav class="bg-white shadow-md">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex justify-between h-16">
      <div class="flex items-center">
        <a href="#" class="text-xl font-semibold text-gray-900">Laravel API Doc</a>
      </div>
      <div class="hidden sm:-my-px sm:flex sm:space-x-8 items-center">
        
      @forelse($files as $f)
        <a class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium text-gray-500" href="/swagger/{{ $f['prefix'] ? $f['prefix'].'/' : '' }}{{ $f['file'] }}">{{ $f['file'] }}</a>
      @empty
        <a class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium text-gray-500">No swagger files found in public/{{ config('laraswag.public_path', 'swagger_ui') }}</a>
      @endforelse
        
      </div>
    </div>
  </div>
</nav>

  <!-- Swagger UI container -->
  <div id="swagger-ui" class="p-4"></div>

  <script src="https://unpkg.com/swagger-ui-dist@4/swagger-ui-bundle.js"></script>

@yield('content')
<script>
  window.onload = function() {
    const ui = SwaggerUIBundle({
        url: '/{{ config('laraswag.public_path', 'swagger_ui') }}/{{$path}}',
        dom_id: '#swagger-ui',
        presets: [SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout',
        persistAuthorization: true, // Persist auth tokens
    });

  window.ui = ui;
};
</script>

</body>
</html>