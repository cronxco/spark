## Playwright Browser Automation (Fetch Integration)

The Fetch integration supports optional browser automation via Playwright for fetching JavaScript-heavy sites, bypassing robot detection, and managing cookie sessions.

### Architecture

```
Laravel (PHP) → FetchEngineManager → Smart Router
                                        ├─→ HTTP (default, fast)
                                        └─→ Playwright (when needed)
                                              ↓
                                        Node.js Worker (Express API)
                                              ↓
                                        Chrome (CDP on port 9222)
                                              ↓
                                        VNC (debugging on port 5900)
```

### Key Components

**PHP Layer:**

- `FetchEngineManager`: Smart routing between HTTP and Playwright
- `PlaywrightFetchClient`: HTTP client to communicate with Node.js worker
- `FetchSingleUrl` job: Updated to use engine manager

**Node.js Worker:**

- Location: `docker/playwright/index.js`
- Express REST API with endpoints: `/fetch`, `/cookies/:domain`, `/health`
- Connects to Chrome via CDP (Chrome DevTools Protocol)
- Automatic reconnection on disconnect (max 5 attempts)

**Docker Services:**

- `chrome`: Chromium browser with VNC and CDP endpoint
- `playwright-worker`: Node.js service running Playwright

### Starting Playwright Services

```bash
# Start with Docker Compose profile

sail up -d --profile playwright

# Or start individual services

docker-compose up chrome playwright-worker -d

# Check service status

sail ps

curl http://localhost:3000/health
```

### Smart Routing Logic

The `FetchEngineManager` automatically selects the appropriate fetch method:

1. **Always HTTP if:**
    - Playwright disabled globally (`PLAYWRIGHT_ENABLED=false`)
    - Webpage has `playwright_preference: 'http'`
2. **Always Playwright if:**
    - Webpage has `requires_playwright: true` (learned from past success)
    - Webpage has `playwright_preference: 'playwright'`
    - Domain in JS-required list (`PLAYWRIGHT_JS_DOMAINS` env var)
3. **Auto-escalate to Playwright if:**
    - Recent HTTP errors with robot/CAPTCHA detection
    - Paywall detected
    - 2+ consecutive failures
4. **Fallback to HTTP if:**
    - Playwright worker unavailable
    - Playwright fetch fails

### Cookie Extraction from Browser

Users can manually interact with the VNC browser to log in, then extract cookies:

1. Open VNC client to `localhost:5900` (password from env)
2. Navigate and log in to target site
3. In Fetch UI (Cookies tab), enter domain and click "Extract from Browser"
4. Cookies are captured from Playwright browser context and stored

**UI Flow:**

- Cookies tab shows "Extract from Browser" button when Playwright available
- Alert box with VNC link for manual browser access
- Extracted cookies stored in same `auth_metadata.domains` structure

### Configuration

**Environment Variables:**

```env

PLAYWRIGHT_ENABLED=true

PLAYWRIGHT_WORKER_URL=http://playwright-worker:3000

PLAYWRIGHT_TIMEOUT=30000

PLAYWRIGHT_SCREENSHOT_ENABLED=true

PLAYWRIGHT_AUTO_ESCALATE=true

PLAYWRIGHT_JS_DOMAINS=twitter.com,x.com,instagram.com,facebook.com

CHROME_VNC_PORT=5900

CHROME_CDP_PORT=9222

CHROME_VNC_URL=vnc://localhost:5900

CHROME_VNC_PASSWORD=spark-dev-vnc
```

**Services Configuration:**

See `config/services.php` -> `'playwright'` array

### Metadata Tracking

EventObject (fetch_webpage) metadata includes:

- `last_fetch_method`: "http", "playwright", or "http (fallback)"
- `requires_playwright`: Boolean flag (learned automatically)
- `playwright_learned_at`: Timestamp when Playwright requirement was learned
- `playwright_preference`: User override ("auto", "http", "playwright")

### UI Features

**Subscribed URLs Tab:**

- Badge showing fetch method (HTTP/Playwright) on each URL card

**Cookies Tab:**

- "Extract from Browser" button (when Playwright available)
- VNC link in info alert
- Same cookie formats supported

**Playwright Tab (new, conditional):**

- Service status indicator
- Fetch method statistics (requires Playwright, prefers HTTP, auto)
- How it works explanation
- VNC access button
- JavaScript-required domains list

### Testing

Playwright integration includes comprehensive tests:

```bash

sail artisan test --filter FetchPlaywrightTest
```

Tests cover:

- Engine manager routing logic
- HTTP fallback when Playwright unavailable
- Learning Playwright requirements
- Auto-escalation on robot detection
- JS-required domain handling
- Metadata storage

### Troubleshooting

**Worker not connecting:**

```bash
# Check Chrome service

curl http://chrome:9222/json/version

# Check worker health

curl http://localhost:3000/health

# View worker logs

sail logs -f playwright-worker
```

**VNC not accessible:**

- Ensure port 5900 is forwarded in docker-compose.yml
- Check VNC password matches `CHROME_VNC_PASSWORD`
- Use VNC client (e.g., RealVNC, TigerVNC, macOS Screen Sharing)

**High resource usage:**

- Limit concurrent Playwright jobs in Horizon config
- Disable screenshots if not needed
- Consider using Playwright only for problematic URLs

### Worker API Reference

See `docker/playwright/README.md` for full API documentation.

### Production Considerations

- **Resource limits**: Set memory limits in docker-compose.yml
- **VNC access**: Disable VNC port in production or use SSH tunnel
- **CDP security**: Never expose port 9222 publicly
- **Scaling**: Use multiple worker instances with load balancer for high volume
- **Monitoring**: Track Playwright success/failure rates in Sentry
