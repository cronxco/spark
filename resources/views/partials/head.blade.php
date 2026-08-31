<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? config('app.name') }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icons/Spark-iOS-Default-60x60@3x.png">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

@if (config('sentry.js.dsn'))
<script>
    window.SENTRY_DSN = '{{ config('sentry.js.dsn') }}';
</script>
@endif
<script>
    window.SENTRY_RELEASE = '{{ config('sentry.release') }}';
    window.SENTRY_ENVIRONMENT = '{{ app()->environment() }}';
</script>
