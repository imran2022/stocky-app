<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Live Sales Display</title>
    <link rel="icon" href="{{ asset('images/' . (($app_settings->favicon ?? null) ?: 'favicon.ico')) }}">
    <style>
      html,body{margin:0;min-height:100%;background:#060b16;color:#fff;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
      #real-time-sales-display{min-height:100vh}
    </style>
</head>
<body>
  <div id="real-time-sales-display"></div>
  <script>
    window.__RTSD_TOKEN__ = @json($displayToken);
    window.__RTSD_LOGO__ = @json(asset('images/' . (($app_settings->logo ?? null) ?: 'logo.png')));
    window.__RTSD_COMPANY__ = @json(($app_settings->CompanyName ?? null) ?: ($app_settings->app_name ?? 'Stocky'));
  </script>
  @php
    $displayJs = public_path('js/realtime-sales-display/app.js');
    $displayCss = public_path('js/realtime-sales-display/app.css');
  @endphp
  @if(file_exists($displayCss))
    <link rel="stylesheet" href="/js/realtime-sales-display/app.css?v={{ filemtime($displayCss) }}">
  @endif
  @if(file_exists($displayJs))
    <script type="module" src="/js/realtime-sales-display/app.js?v={{ filemtime($displayJs) }}"></script>
  @else
    <p style="padding:24px">Display assets are not built. Run <code>npm run build:realtime-sales-display</code>.</p>
  @endif
</body>
</html>
