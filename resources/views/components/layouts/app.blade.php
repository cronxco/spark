<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable = no">
        <meta name="mobile-web-app-capable" content="yes">
        <link rel="manifest" href="/manifest.json" />
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#F5F5F5" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#010E19" media="(prefers-color-scheme: dark)">
        <link rel="shortcut icon" href="/favicon.ico">
        <link rel="icon" sizes="16x16 32x32 64x64" href="/favicon.ico">
        <link rel="icon" type="image/png" sizes="196x196" href="/favicon-192.png">
        <link rel="icon" type="image/png" sizes="160x160" href="/favicon-160.png">
        <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96.png">
        <link rel="icon" type="image/png" sizes="64x64" href="/favicon-64.png">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
        <link rel="apple-touch-icon" href="/favicon-57.png">
        <link rel="apple-touch-icon" sizes="114x114" href="/favicon-114.png">
        <link rel="apple-touch-icon" sizes="72x72" href="/favicon-72.png">
        <link rel="apple-touch-icon" sizes="144x144" href="/favicon-144.png">
        <link rel="apple-touch-icon" sizes="60x60" href="/favicon-60.png">
        <link rel="apple-touch-icon" sizes="120x120" href="/favicon-120.png">
        <link rel="apple-touch-icon" sizes="76x76" href="/favicon-76.png">
        <link rel="apple-touch-icon" sizes="152x152" href="/favicon-152.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/favicon-180.png">
        <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link aysnc href="https://fonts.googleapis.com/css2?family=PT+Mono&display=swap" rel="stylesheet">
        <link async rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap">
        <link async rel="stylesheet" href="https://fonts.googleapis.com/css?family=Comfortaa:300,400,500,600,700&display=swap" />

        <!-- Scripts -->
        <script async src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
        <!-- <script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js" async></script> -->
        <script defer src="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
        {{-- <script>hljs.highlightAll();</script> --}}
        <script src="https://unpkg.com/pretty-json-custom-element/index.js" async></script>
        <!-- <script src="https://cdn.jsdelivr.net/npm/chart.js@^4"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-trendline/dist/chartjs-plugin-trendline.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/luxon@^2"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@^1"></script> -->

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <!-- <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark-dimmed.min.css"> -->
</head>
<body class="font-sans antialiased">

    {{-- The navbar with `sticky` and `full-width` --}}
    <x-nav sticky full-width class="bg-base-200">

        <x-slot:brand>
            {{-- Brand --}}
            <x-app-brand />
        </x-slot:brand>

        {{-- Right side actions --}}
        <x-slot:actions>
            <x-button label="Home" icon="fas.home" link="/" class="btn-ghost btn-sm lg:hidden" responsive />
            <x-button label="Search" icon="fab.searchengin" link="/search" class="btn-ghost btn-sm" responsive />

            {{-- User --}}
            @if ($user = auth()->user())
            <x-dropdown>
                <x-slot:trigger>
                    <div class="avatar inline-block align-middle">
                    <div class="h-[32px] rounded-full">
                        <x-avatar placeholder="{{ auth()->user()->initials() }}"/>
                    </div>
                    </div>
                </x-slot:trigger>
                <x-menu title="">
                    <x-menu-item title="Profile" icon="fas.user" link="{{ route('settings.profile') }}" :active="request()->routeIs('settings.profile')"/>
                    <x-menu-item title="Password" icon="fas.lock" link="{{ route('settings.password') }}" :active="request()->routeIs('settings.password')"/>
                    <x-menu-item title="Appearance" icon="fas.palette" link="{{ route('settings.appearance') }}" :active="request()->routeIs('settings.appearance')"/>
                    <x-menu-item title="API Tokens" icon="fas.key" link="{{ route('settings.api-tokens') }}" :active="request()->routeIs('settings.api-tokens')"/>
                </x-menu>
                <form method="POST" action="{{ route('logout') }}" x-data>
                @csrf
                <x-menu-item @click.prevent="$root.submit();" title="Logout" />
                </form>
            </x-dropdown>
            @endif
        </x-slot:actions>
    </x-nav>

    {{-- The main content with `full-width` --}}
    <x-main with-nav full-width>

        {{-- This is a sidebar that works also as a drawer on small screens --}}
        {{-- Notice the `main-drawer` reference here --}}
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-200">
            <x-menu title="">
            <div class="lg:hidden">
              <ul>
                <li>
                  <x-app-brand />
                </li>
              </ul>

            </div>
            </x-menu>

            <x-menu title="" class="p-1">
                <x-menu-item title="Integrations" icon="fas.gear" link="{{ route('integrations.index') }}" :active="request()->routeIs('integrations.*')"/>
            </x-menu>
        </x-slot:sidebar>

        {{-- The `$slot` goes here --}}
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>

    </x-main>

    {{--  TOAST area --}}
    <x-toast />



</body>

</html>
