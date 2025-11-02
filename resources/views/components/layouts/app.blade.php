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

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <!-- <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark-dimmed.min.css"> -->

    <!-- EasyMDE (required by MaryUI x-markdown) -->
    <link rel="stylesheet" href="https://unpkg.com/easymde/dist/easymde.min.css">
    <script src="https://unpkg.com/easymde/dist/easymde.min.js"></script>

    @if (env('VITE_SENTRY_DSN'))
    <script>
        window.SENTRY_DSN = "{{ env('VITE_SENTRY_DSN') }}";
    </script>
    @endif
    <script>
        window.SENTRY_RELEASE = "{{ env('SENTRY_RELEASE') }}";
        window.SENTRY_ENVIRONMENT = "{{ app()->environment() }}";
    </script>
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
            <label for="main-drawer" class="btn btn-ghost btn-sm lg:hidden" title="Menu" aria-label="Menu" data-hotkey="b">
                <x-icon name="o-bars-3" class="w-5 h-5" />
            </label>

            {{-- Global Progress Indicator --}}
            @livewire('global-progress-indicator')

            <livewire:spotlight-toggle />

            <livewire:help-toggle />

            {{-- User --}}
            @if ($user = auth()->user())
            <x-dropdown>
                <x-slot:trigger>
                    <div class="avatar inline-block align-middle">
                        <div class="h-[32px] rounded-full">
                            <x-avatar placeholder="{{ auth()->user()->initials() }}" />
                        </div>
                    </div>
                </x-slot:trigger>
                <x-menu title="">
                    <x-menu-item title="Profile" icon="fas.user" link="{{ route('settings.profile') }}" :active="request()->routeIs('settings.profile')" />
                    <x-menu-item title="Password" icon="fas.lock" link="{{ route('settings.password') }}" :active="request()->routeIs('settings.password')" />
                    <x-menu-item title="Sessions" icon="fas.desktop" link="{{ route('settings.sessions') }}" :active="request()->routeIs('settings.sessions')" />
                    <x-menu-item title="Notifications" icon="fas.bell" link="{{ route('settings.notifications') }}" :active="request()->routeIs('settings.notifications')" />
                    <x-menu-item title="API Tokens" icon="fas.key" link="{{ route('settings.api-tokens') }}" :active="request()->routeIs('settings.api-tokens')" />
                    <x-menu-item
                        title="Reset Card Views"
                        icon="o-arrow-path"
                        x-data
                        @click.prevent="
                            if (confirm('Reset all card view history? This will show all cards again.')) {
                                const userId = '{{ auth()->id() }}';
                                const prefix = `spark_card_views_${userId}_`;
                                const keysToRemove = [];
                                for (let i = 0; i < localStorage.length; i++) {
                                    const key = localStorage.key(i);
                                    if (key && key.startsWith(prefix)) {
                                        keysToRemove.push(key);
                                    }
                                }
                                keysToRemove.forEach(key => localStorage.removeItem(key));

                                // Clear server-side cache via API
                                fetch('/api/clear-card-cache', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                                    }
                                }).catch(err => console.error('Failed to clear cache:', err));

                                alert('Card view history cleared! All cards will show again on your next visit.');
                                window.location.reload();
                            }
                        " />
                </x-menu>
                <x-menu-separator />
                <form method="POST" action="{{ route('logout') }}" x-data>
                    @csrf
                    <x-menu-item @click.prevent="$el.closest('form').submit();" title="Logout" />
                </form>
            </x-dropdown>
            @endif
        </x-slot:actions>
    </x-nav>

    {{-- The main content with `full-width` --}}
    <x-main with-nav full-width>

        {{-- This is a sidebar that works also as a drawer on small screens --}}
        {{-- Notice the `main-drawer` reference here --}}
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-200" version version-route="{{ route('updates.index') }}">
            <input id="main-drawer" type="checkbox" class="hidden" />
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
                <x-menu-item title="Today" icon="fas.calendar-day" link="{{ route('today.main') }}" :active="request()->routeIs('today.*')" data-hotkey="g d" />
                <x-menu-item title="Yesterday" icon="fas.calendar-minus" link="{{ route('day.yesterday') }}" :active="request()->routeIs('day.*')" data-hotkey="g y" />
                <x-menu-item title="Tomorrow" icon="fas.calendar-plus" link="{{ route('tomorrow') }}" :active="request()->routeIs('tomorrow')" />

                <x-menu-item title="Tags" icon="fas.tag" link="{{ route('tags.index') }}" :active="request()->routeIs('tags.*')" data-hotkey="g t" />
                <x-menu-item title="Bookmarks" icon="fas.bookmark" link="{{ route('bookmarks.index') }}" :active="request()->routeIs('bookmarks.*')" data-hotkey="g b" />
                <x-menu-item title="Money" icon="fas.pound-sign" link="{{ route('money') }}" :active="request()->routeIs('money.*')" data-hotkey="g m" />
                <x-menu-item title="Metrics" icon="fas.chart-line" link="{{ route('metrics.index') }}" :active="request()->routeIs('metrics.*')" data-hotkey="g x" />
                <x-menu-item title="Updates" icon="fas.cloud-arrow-down" link="{{ route('updates.index') }}" :active="request()->routeIs('updates.*')" data-hotkey="g u" />

                <x-menu-sub title="Settings" icon="fas.cog" :active="request()->routeIs('settings.*')" data-hotkey="g s">
                    <x-menu-item title="Profile" icon="fas.user" link="{{ route('settings.profile') }}" :active="request()->routeIs('settings.profile')" />
                    <x-menu-item title="Password" icon="fas.lock" link="{{ route('settings.password') }}" :active="request()->routeIs('settings.password')" />
                    <x-menu-item title="Sessions" icon="fas.desktop" link="{{ route('settings.sessions') }}" :active="request()->routeIs('settings.sessions')" />
                    <x-menu-item title="Notifications" icon="fas.bell" link="{{ route('settings.notifications') }}" :active="request()->routeIs('settings.notifications')" />
                    <x-menu-item title="Integrations" icon="fas.puzzle-piece" link="{{ route('integrations.index') }}" :active="request()->routeIs('integrations.*')" />
                    <x-menu-item title="API Tokens" icon="fas.key" link="{{ route('settings.api-tokens') }}" :active="request()->routeIs('settings.api-tokens')" />
                </x-menu-sub>

                <x-menu-sub title="Admin" icon="fas.shield-halved" :active="request()->routeIs('admin.*')" data-hotkey="g a">
                    <x-menu-item title="Sense Check" icon="fas.brain" link="{{ route('admin.sense-check.index') }}" :active="request()->routeIs('admin.sense-check.*')" />
                    <x-menu-item title="Activity" icon="fas.history" link="{{ route('admin.activity.index') }}" :active="request()->routeIs('admin.activity.*')" />
                    <x-menu-item title="Events" icon="fas.list" link="{{ route('admin.events.index') }}" :active="request()->routeIs('admin.events.*')" />
                    <x-menu-item title="Objects" icon="fas.cube" link="{{ route('admin.objects.index') }}" :active="request()->routeIs('admin.objects.*')" />
                    <x-menu-item title="Blocks" icon="fas.cubes" link="{{ route('admin.blocks.index') }}" :active="request()->routeIs('admin.blocks.*')" />
                    <x-menu-item title="GoCardless" icon="fas.credit-card" link="{{ route('admin.gocardless.index') }}" :active="request()->routeIs('admin.gocardless.*')" />
                    <x-menu-item title="Migrations" icon="fas.cog" link="{{ route('admin.migrations.index') }}" :active="request()->routeIs('admin.migrations.*')" />
                    <x-menu-item title="Logs" icon="fas.file-lines" link="{{ route('admin.logs.index') }}" :active="request()->routeIs('admin.logs.*')" />
                    <x-menu-item title="Bin" icon="fas.trash" link="{{ route('admin.bin.index') }}" :active="request()->routeIs('admin.bin.*')" />
                </x-menu-sub>
            </x-menu>
        </x-slot:sidebar>

        {{-- The `$slot` goes here --}}
        <x-slot:content class="bg-base-100">
            {{ $slot }}
        </x-slot:content>

    </x-main>

    {{-- TOAST area --}}
    <x-toast />

    <script>
        (function() {
            if (window.__copyListenerAdded) {
                return;
            }
            window.__copyListenerAdded = true;

            window.addEventListener('copy-to-clipboard', async function(event) {
                var detail = event && event.detail ? event.detail : {};
                var text = typeof detail.url === 'string' ? detail.url : '';

                if (!text) {
                    return;
                }

                try {
                    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                        await navigator.clipboard.writeText(text);
                    } else {
                        var textarea = document.createElement('textarea');
                        textarea.value = text;
                        textarea.setAttribute('readonly', '');
                        textarea.style.position = 'absolute';
                        textarea.style.left = '-9999px';
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                    }
                } catch (err) {
                    console.error('Failed to copy to clipboard', err);
                }
            });
        })();
    </script>

    <!-- Global Card Streams Component -->
    <livewire:card-streams />

    <!-- Spotlight Command Palette -->
    @livewire('spotlight-pro')

    <!-- Keyboard Shortcuts Help Modal -->
    <livewire:hotkey-help-modal />

</body>

</html>
