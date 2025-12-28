<div class="card bg-base-200 shadow mb-6">
    <div class="card-body">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold">API Access Tokens</h3>
                        <x-button wire:click="$set('showTokenCreateModal', true)" class="btn-primary btn-sm">
                            <x-icon name="fas.plus" class="w-4 h-4" />
                            Create Token
                        </x-button>
                    </div>

                    <div class="alert alert-warning mb-4">
                        <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
                        <div class="text-sm">
                            <p class="font-semibold">Keep your tokens secure!</p>
                            <p>Treat API tokens like passwords. Anyone with your token can save bookmarks to your account.</p>
                        </div>
                    </div>

                    <!-- Token List -->
                    @if (count($apiTokens) > 0)
                    <div class="space-y-3">
                        @foreach ($apiTokens as $token)
                        <div class="card bg-base-100 shadow-sm">
                            <div class="card-body p-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <h4 class="font-medium">{{ $token['name'] }}</h4>
                                        <div class="flex flex-wrap gap-3 text-sm text-base-content/70 mt-1">
                                            <span class="flex items-center gap-1">
                                                <x-icon name="fas.calendar" class="w-3 h-3" />
                                                Created {{ \Carbon\Carbon::parse($token['created_at'])->format('M j, Y') }}
                                            </span>
                                            @if ($token['last_used_at'])
                                            <span class="flex items-center gap-1">
                                                <x-icon name="fas.clock" class="w-3 h-3" />
                                                Last used {{ \Carbon\Carbon::parse($token['last_used_at'])->diffForHumans() }}
                                            </span>
                                            @else
                                            <span class="text-base-content/50">Never used</span>
                                            @endif
                                        </div>
                                    </div>
                                    <x-button
                                        wire:click="revokeApiToken({{ $token['id'] }})"
                                        wire:confirm="Are you sure you want to revoke this token? This cannot be undone."
                                        class="btn-error btn-outline btn-sm">
                                        <x-icon name="fas.trash" class="w-4 h-4" />
                                        Revoke
                                    </x-button>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="text-center py-8 bg-base-100 rounded-lg">
                        <x-icon name="fas.key" class="w-12 h-12 mx-auto text-base-content/50 mb-3" />
                        <p class="text-base-content/70">No API tokens created yet.</p>
                        <p class="text-sm text-base-content/50 mt-1">Create one to start using the API.</p>
                    </div>
                    @endif
                </div>
            </div>

            <!-- API Endpoint Information -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-4">API Endpoint</h3>

                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text font-medium">Endpoint URL</span>
                        </label>
                        <div class="flex gap-2">
                            <input
                                type="text"
                                value="{{ url('/api/fetch/bookmarks') }}"
                                readonly
                                class="input input-bordered flex-1 font-mono text-sm"
                                id="api-endpoint-url" />
                            <x-button
                                onclick="navigator.clipboard.writeText(document.getElementById('api-endpoint-url').value); window.dispatchEvent(new CustomEvent('toast-success', { detail: { message: 'Copied to clipboard!' } }))"
                                class="btn-outline">
                                <x-icon name="o-clipboard" class="w-4 h-4" />
                                Copy
                            </x-button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Parameter</th>
                                    <th>Type</th>
                                    <th>Required</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code class="text-xs">url</code></td>
                                    <td><span class="badge badge-sm badge-info">string</span></td>
                                    <td><x-badge value="Yes" class="badge-success badge-sm" /></td>
                                    <td>The URL to bookmark</td>
                                </tr>
                                <tr>
                                    <td><code class="text-xs">fetch_immediately</code></td>
                                    <td><span class="badge badge-sm badge-info">boolean</span></td>
                                    <td><x-badge value="No" class="badge-neutral badge-sm" /></td>
                                    <td>Fetch content right away (default: true)</td>
                                </tr>
                                <tr>
                                    <td><code class="text-xs">force_refresh</code></td>
                                    <td><span class="badge badge-sm badge-info">boolean</span></td>
                                    <td><x-badge value="No" class="badge-neutral badge-sm" /></td>
                                    <td>Force re-fetch if exists (default: false)</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Integration Instructions -->
            <div class="space-y-6">
                <!-- Apple Shortcuts -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                                <x-icon name="fas.mobile-screen" class="w-5 h-5 text-primary" />
                            </div>
                            <h3 class="text-lg font-semibold">Apple Shortcuts</h3>
                        </div>

                        <x-collapse>
                            <x-slot:heading>
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.circle-info" class="w-5 h-5" />
                                    Setup Instructions
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="prose prose-sm max-w-none">
                                    @if ($temporaryTokenValue)
                                    <div class="alert alert-success mb-4">
                                        <x-icon name="fas.wand-magic-sparkles" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>✨ Auto-populated!</strong> Your new token is ready to use below.</p>
                                            <p class="text-xs opacity-70 mt-1">The actual token value is showing in all examples (will reset on page refresh)</p>
                                        </div>
                                    </div>
                                    @elseif (!empty($apiTokens))
                                    <div class="alert alert-info mb-4">
                                        <x-icon name="fas.circle-info" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Using your token:</strong> {{ $this->latestTokenName() }}</p>
                                            <p class="text-xs opacity-70 mt-1">Paste your actual token value in place of the placeholder</p>
                                        </div>
                                    </div>
                                    @endif

                                    <ol class="text-sm space-y-3">
                                        <li>Open the <strong>Shortcuts</strong> app on your iPhone or iPad</li>
                                        <li>Tap the <strong>+</strong> button to create a new shortcut</li>
                                        <li>Add a <strong>"Get URLs from Input"</strong> action</li>
                                        <li>Add a <strong>"Get Contents of URL"</strong> action with these settings:
                                            <ul class="mt-2">
                                                <li>URL: <code class="bg-base-300 px-2 py-1 rounded text-xs">{{ url('/api/fetch/bookmarks') }}</code></li>
                                                <li>Method: <strong>POST</strong></li>
                                                <li>Headers:
                                                    <ul class="mt-1">
                                                        <li><code class="bg-base-300 px-1 rounded text-xs">Authorization: Bearer {{ $this->getTokenForExamples() }}</code></li>
                                                        <li><code class="bg-base-300 px-1 rounded text-xs">Content-Type: application/json</code></li>
                                                        <li><code class="bg-base-300 px-1 rounded text-xs">Accept: application/json</code></li>
                                                    </ul>
                                                </li>
                                                <li>Request Body: <strong>JSON</strong></li>
                                                <li>Body content: <code class="bg-base-300 px-2 py-1 rounded text-xs">{"url": "URL from Input"}</code></li>
                                            </ul>
                                        </li>
                                        <li>Add a <strong>"Show Notification"</strong> action to confirm success</li>
                                        <li>Name your shortcut (e.g., "Save to Fetch")</li>
                                        <li>Enable <strong>"Show in Share Sheet"</strong> in settings</li>
                                    </ol>
                                    <div class="alert alert-info mt-4">
                                        <x-icon name="fas.lightbulb" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Pro tip:</strong> You can now use the Share Sheet from Safari or any app to save URLs directly to Fetch!</p>
                                        </div>
                                    </div>
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    </div>
                </div>

                <!-- Chrome Bookmarklet -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-secondary/10 flex items-center justify-center">
                                <x-icon name="fas.globe" class="w-5 h-5 text-secondary" />
                            </div>
                            <h3 class="text-lg font-semibold">Browser Bookmarklet</h3>
                        </div>

                        <x-collapse>
                            <x-slot:heading>
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.circle-info" class="w-5 h-5" />
                                    Setup Instructions
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="prose prose-sm max-w-none">
                                    @if ($temporaryTokenValue)
                                    <div class="alert alert-success mb-4">
                                        <x-icon name="fas.wand-magic-sparkles" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>✨ Auto-populated!</strong> Your new token is ready to use below.</p>
                                            <p class="text-xs opacity-70 mt-1">The actual token value is showing (will reset on page refresh)</p>
                                        </div>
                                    </div>
                                    @elseif (!empty($apiTokens))
                                    <div class="alert alert-info mb-4">
                                        <x-icon name="fas.circle-info" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Using your token:</strong> {{ $this->latestTokenName() }}</p>
                                            <p class="text-xs opacity-70 mt-1">Paste your actual token value in place of the placeholder</p>
                                        </div>
                                    </div>
                                    @endif

                                    <p class="text-sm mb-3">{{ $temporaryTokenValue ? 'Ready to copy! The code below has your actual token:' : (!empty($apiTokens) ? 'Copy the code below and replace the placeholder with your token:' : 'Create a token first, then use this code:') }}</p>

                                    <div class="bg-base-300 p-4 rounded-lg mb-4">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-sm font-medium">Bookmarklet Code</p>
                                            <x-button
                                                onclick="navigator.clipboard.writeText(document.getElementById('bookmarklet-code').textContent); window.dispatchEvent(new CustomEvent('toast-success', { detail: { message: 'Bookmarklet copied to clipboard!' } }))"
                                                class="btn-xs btn-outline">
                                                <x-icon name="o-clipboard" class="w-3 h-3" />
                                                Copy
                                            </x-button>
                                        </div>
                                        <pre class="text-xs overflow-x-auto" id="bookmarklet-code"><code>javascript:(function(){
    const token = '{{ $this->getTokenForExamples() }}';
    const url = window.location.href;
    fetch('{{ url('/api/fetch/bookmarks') }}', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ url })
    })
    .then(r => r.json())
    .then(d => alert(d.success ? '✓ Saved to Fetch!' : 'Error: ' + d.message))
    .catch(e => alert('Error saving bookmark'));
})();</code></pre>
                                    </div>

                                    <p class="text-sm mb-2"><strong>Step 1:</strong> Copy the code above and replace the token placeholder with your actual token value</p>
                                    <p class="text-sm mb-2"><strong>Step 2:</strong> Create a new bookmark and paste the code as the URL</p>
                                    <p class="text-sm"><strong>Step 3:</strong> Click the bookmark on any page to save it to Fetch</p>

                                    @if (empty($apiTokens))
                                    <div class="alert alert-warning mt-4">
                                        <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p>Create a token above first, then come back to get your personalized bookmarklet code!</p>
                                        </div>
                                    </div>
                                    @endif
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    </div>
                </div>

                <!-- cURL Example -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-accent/10 flex items-center justify-center">
                                <x-icon name="o-command-line" class="w-5 h-5 text-accent" />
                            </div>
                            <h3 class="text-lg font-semibold">Command Line (cURL)</h3>
                        </div>

                        <x-collapse>
                            <x-slot:heading>
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.code" class="w-5 h-5" />
                                    Example Request
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="prose prose-sm max-w-none">
                                    @if ($temporaryTokenValue)
                                    <div class="alert alert-success mb-4">
                                        <x-icon name="fas.wand-magic-sparkles" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>✨ Auto-populated!</strong> Your new token is in the command below.</p>
                                            <p class="text-xs opacity-70 mt-1">Copy and run it directly! (resets on page refresh)</p>
                                        </div>
                                    </div>
                                    @elseif (!empty($apiTokens))
                                    <div class="alert alert-info mb-4">
                                        <x-icon name="fas.circle-info" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Using your token:</strong> {{ $this->latestTokenName() }}</p>
                                            <p class="text-xs opacity-70 mt-1">Replace the placeholder with your actual token</p>
                                        </div>
                                    </div>
                                    @endif

                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-sm font-medium">Request Example</p>
                                        <x-button
                                            onclick="navigator.clipboard.writeText(document.getElementById('curl-code').textContent); window.dispatchEvent(new CustomEvent('toast-success', { detail: { message: 'cURL command copied!' } }))"
                                            class="btn-xs btn-outline">
                                            <x-icon name="o-clipboard" class="w-3 h-3" />
                                            Copy
                                        </x-button>
                                    </div>
                                    <pre class="bg-base-300 p-4 rounded-lg text-xs overflow-x-auto" id="curl-code"><code>curl -X POST {{ url('/api/fetch/bookmarks') }} \
  -H "Authorization: Bearer {{ $this->getTokenForExamples() }}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"url": "https://example.com/article", "fetch_immediately": true}'</code></pre>

                                    <p class="text-sm mt-4"><strong>Response:</strong></p>
                                    <pre class="bg-base-300 p-4 rounded-lg text-xs overflow-x-auto"><code>{
  "success": true,
  "bookmark": {
    "id": "uuid",
    "url": "https://example.com/article",
    "title": "Article Title",
    "status": "pending"
  },
  "job_dispatched": true
}</code></pre>
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    </div>
                </div>

                <!-- JavaScript Example -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-info/10 flex items-center justify-center">
                                <x-icon name="o-code-bracket-square" class="w-5 h-5 text-info" />
                            </div>
                            <h3 class="text-lg font-semibold">JavaScript / Browser Extension</h3>
                        </div>

                        <x-collapse>
                            <x-slot:heading>
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.code" class="w-5 h-5" />
                                    Example Code
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="prose prose-sm max-w-none">
                                    @if ($temporaryTokenValue)
                                    <div class="alert alert-success mb-4">
                                        <x-icon name="fas.wand-magic-sparkles" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>✨ Auto-populated!</strong> Your new token is in the code below.</p>
                                            <p class="text-xs opacity-70 mt-1">Ready to use! (resets on page refresh)</p>
                                        </div>
                                    </div>
                                    @elseif (!empty($apiTokens))
                                    <div class="alert alert-info mb-4">
                                        <x-icon name="fas.circle-info" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Using your token:</strong> {{ $this->latestTokenName() }}</p>
                                            <p class="text-xs opacity-70 mt-1">Replace the placeholder with your actual token</p>
                                        </div>
                                    </div>
                                    @endif

                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-sm font-medium">JavaScript Code</p>
                                        <x-button
                                            onclick="navigator.clipboard.writeText(document.getElementById('javascript-code').textContent); window.dispatchEvent(new CustomEvent('toast-success', { detail: { message: 'JavaScript code copied!' } }))"
                                            class="btn-xs btn-outline">
                                            <x-icon name="o-clipboard" class="w-3 h-3" />
                                            Copy
                                        </x-button>
                                    </div>
                                    <pre class="bg-base-300 p-4 rounded-lg text-xs overflow-x-auto" id="javascript-code"><code>const API_TOKEN = '{{ $this->getTokenForExamples() }}';
const API_ENDPOINT = '{{ url('/api/fetch/bookmarks') }}';

async function saveToFetch(url) {
  try {
    const response = await fetch(API_ENDPOINT, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${API_TOKEN}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        url: url,
        fetch_immediately: true
      })
    });

    const data = await response.json();

    if (data.success) {
      console.log('Bookmark saved:', data.bookmark);
      return data.bookmark;
    } else {
      throw new Error(data.message || 'Failed to save bookmark');
    }
  } catch (error) {
    console.error('Error saving bookmark:', error);
    throw error;
  }
}

// Usage
saveToFetch('https://example.com/article')
  .then(bookmark => console.log('Saved!', bookmark))
  .catch(error => console.error('Failed:', error));</code></pre>

                                    <div class="alert alert-info mt-4">
                                        <x-icon name="fas.lightbulb" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Browser Extension Tip:</strong> You can use this code in a Chrome/Firefox extension to add a "Save to Fetch" button to your toolbar!</p>
                                        </div>
                                    </div>
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    </div>
                </div>
            </div>
