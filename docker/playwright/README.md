# Playwright Worker for Spark Fetch Integration

This directory contains the Node.js Playwright worker service that enables browser automation for the Spark Fetch integration.

## Overview

The Playwright worker provides a REST API for fetching web content using a real browser (Chromium), which enables:

- **JavaScript execution**: Fetch content from JavaScript-heavy sites (Twitter, Instagram, etc.)
- **Cookie management**: Extract cookies from authenticated browser sessions
- **Robot detection bypass**: Handle CAPTCHA and anti-bot measures
- **Screenshot capture**: Visual snapshots of fetched pages
- **VNC debugging**: Real-time browser observation via VNC

## Architecture

```
Laravel App (PHP)
    ↓ HTTP requests
Playwright Worker (Node.js)
    ↓ Chrome DevTools Protocol (CDP)
Chrome Service (Docker)
    ↓ VNC (optional debugging)
User's VNC Client
```

## API Endpoints

### POST /fetch

Fetch a URL using Playwright browser automation.

**Request:**

```json
{
    "url": "https://example.com",
    "cookies": [
        {
            "name": "session_id",
            "value": "abc123",
            "domain": ".example.com",
            "path": "/",
            "secure": true,
            "httpOnly": true
        }
    ],
    "waitFor": "networkidle",
    "timeout": 30000,
    "screenshot": true,
    "userAgent": "Mozilla/5.0..."
}
```

**Response:**

```json
{
  "success": true,
  "html": "<html>...</html>",
  "title": "Page Title",
  "url": "https://example.com",
  "screenshot": "base64-encoded-png",
  "cookies": [...]
}
```

### GET /cookies/:domain

Extract cookies from the browser session for a specific domain.

**Response:**

```json
{
  "success": true,
  "cookies": [...],
  "count": 5
}
```

### GET /health

Health check endpoint.

**Response:**

```json
{
    "status": "ok",
    "connected": true,
    "reconnectAttempts": 0,
    "timestamp": "2025-01-03T10:00:00.000Z"
}
```

### GET /browser/info

Get browser session information.

**Response:**

```json
{
  "success": true,
  "connected": true,
  "contextCount": 1,
  "contexts": [...]
}
```

## Environment Variables

- `CHROME_CDP_URL`: Chrome DevTools Protocol endpoint (default: `http://chrome:9222`)
- `NODE_ENV`: Node environment (default: `production`)
- `PORT`: HTTP server port (default: `3000`)

## Development

### Local Setup

1. Install dependencies:

```bash
npm install
```

2. Start the Chrome service:

```bash
docker-compose up chrome -d
```

3. Run the worker:

```bash
node index.js
```

### Testing

Test the worker with curl:

```bash
# Health check
curl http://localhost:3000/health

# Fetch a URL
curl -X POST http://localhost:3000/fetch \
  -H "Content-Type: application/json" \
  -d '{"url": "https://example.com", "screenshot": false}'

# Extract cookies
curl http://localhost:3000/cookies/example.com
```

## Logging

The worker uses structured JSON logging:

```json
{
    "timestamp": "2025-01-03T10:00:00.000Z",
    "level": "info",
    "message": "Request completed",
    "method": "POST",
    "path": "/fetch",
    "statusCode": 200,
    "duration": "1234ms"
}
```

View logs in Docker:

```bash
sail logs -f playwright-worker
```

## Error Handling

The worker includes:

- **Automatic reconnection**: Reconnects to Chrome if disconnected (max 5 attempts)
- **Graceful degradation**: Returns error responses instead of crashing
- **Resource cleanup**: Always closes pages and contexts in `finally` blocks
- **Timeout handling**: Configurable timeouts with fallbacks

## Performance Considerations

- **Context isolation**: Each fetch creates a new browser context (isolated cookies/sessions)
- **slowMo**: 100ms delay between operations for stability
- **Network idle**: Optional 5s timeout for complex pages
- **Memory management**: Contexts are closed after each request

## Security Notes

- **CDP access**: Chrome CDP endpoint (9222) should NOT be exposed publicly
- **No authentication**: The worker has no built-in auth (relies on Docker network isolation)
- **Cookie storage**: Cookies are temporarily stored in browser contexts
- **VNC password**: Set a strong VNC password in production

## Troubleshooting

### Worker can't connect to Chrome

**Symptom**: `Browser not connected` errors

**Solution:**

1. Check Chrome service is running: `sail ps`
2. Verify CDP endpoint: `curl http://chrome:9222/json/version`
3. Check Docker network: `docker network inspect spark_sail`

### High memory usage

**Symptom**: Worker consuming excessive RAM

**Solution:**

1. Reduce concurrent requests
2. Ensure contexts are being closed
3. Restart the worker periodically

### Slow fetches

**Symptom**: Requests timing out

**Solution:**

1. Increase `PLAYWRIGHT_TIMEOUT` env var
2. Disable screenshot capture
3. Skip network idle wait for simple pages

## Production Deployment

### Recommended Configuration

```yaml
playwright-worker:
    build: ./docker/playwright
    environment:
        - CHROME_CDP_URL=http://chrome:9222
        - NODE_ENV=production
    deploy:
        resources:
            limits:
                memory: 512M
            reservations:
                memory: 256M
    restart: unless-stopped
    healthcheck:
        test: ["CMD", "wget", "--spider", "http://localhost:3000/health"]
        interval: 30s
        timeout: 10s
        retries: 3
```

### Scaling

For high-volume fetching:

1. **Multiple workers**: Run multiple worker instances with a load balancer
2. **Multiple Chrome instances**: Use Chrome Grid for parallel browsers
3. **Queue system**: Laravel Horizon manages job distribution

## License

Part of the Spark project.
