<x-layouts.auth.card>
    <div class="flex flex-col gap-6">
        <div class="flex flex-col items-center gap-2 text-center">
            <h1 class="text-2xl font-semibold dark:text-white">Authorize Spark iOS</h1>
            <p class="text-sm text-stone-600 dark:text-stone-400">
                @if ($device_name)
                    Allow <strong>{{ $device_name }}</strong> to access your Spark account?
                @else
                    Allow the Spark iOS app to access your account?
                @endif
            </p>
        </div>

        <div class="rounded-md border border-stone-200 bg-stone-50 p-4 text-sm dark:border-stone-800 dark:bg-stone-900 dark:text-stone-300">
            <p class="font-medium dark:text-white">Signed in as</p>
            <p>{{ auth()->user()->name }} ({{ auth()->user()->email }})</p>
        </div>

        <ul class="space-y-2 text-sm text-stone-600 dark:text-stone-400">
            <li class="flex items-start gap-2">
                <x-icon name="o-check" class="mt-0.5 size-4 text-emerald-500" />
                <span>Read your events, blocks, objects, and metrics</span>
            </li>
            <li class="flex items-start gap-2">
                <x-icon name="o-check" class="mt-0.5 size-4 text-emerald-500" />
                <span>Send push notifications and live activity updates</span>
            </li>
            <li class="flex items-start gap-2">
                <x-icon name="o-check" class="mt-0.5 size-4 text-emerald-500" />
                <span>Submit health samples and check-ins</span>
            </li>
        </ul>

        <form method="POST" action="{{ route('oauth.approve') }}" class="flex flex-col gap-3">
            @csrf
            <input type="hidden" name="client_id" value="{{ $client_id }}">
            <input type="hidden" name="redirect_uri" value="{{ $redirect_uri }}">
            <input type="hidden" name="response_type" value="{{ $response_type }}">
            <input type="hidden" name="code_challenge" value="{{ $code_challenge }}">
            <input type="hidden" name="code_challenge_method" value="{{ $code_challenge_method }}">
            <input type="hidden" name="state" value="{{ $state }}">
            <input type="hidden" name="scope" value="{{ $scope }}">
            @if ($device_name)
                <input type="hidden" name="device_name" value="{{ $device_name }}">
            @endif

            <button type="submit" class="btn btn-primary w-full">
                Authorize
            </button>

            <a href="{{ route('home') }}" class="btn btn-ghost w-full">
                Cancel
            </a>
        </form>
    </div>
</x-layouts.auth.card>
