const express = require("express");
const { chromium } = require("playwright-extra");
const StealthPlugin = require("puppeteer-extra-plugin-stealth");

// Enable stealth mode if configured
const STEALTH_ENABLED = process.env.STEALTH_ENABLED !== "false"; // Default: true
if (STEALTH_ENABLED) {
    chromium.use(StealthPlugin());
    console.log("Stealth mode enabled");
}

const app = express();
app.use(express.json({ limit: "50mb" }));

let browser = null;
let reconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 5;

// Context cache for persistence
const contextCache = new Map();
const contextTimestamps = new Map();
const CONTEXT_TTL = parseInt(process.env.CONTEXT_TTL || "1800000"); // 30 min default

// Logging helper
function log(level, message, meta = {}) {
    const timestamp = new Date().toISOString();
    console.log(JSON.stringify({ timestamp, level, message, ...meta }));
}

// Helper function to get or create a browser context with optional caching
async function getOrCreateContext(
    domain,
    cookies,
    userAgent,
    usePersistence,
    useDefaultContext,
) {
    // If explicitly requested to use default context (has extensions, persistent profile)
    if (useDefaultContext) {
        const contexts = browser.contexts();

        if (contexts.length > 0) {
            const defaultContext = contexts[0];

            // Apply cookies if provided
            if (cookies && Array.isArray(cookies) && cookies.length > 0) {
                await defaultContext.addCookies(cookies);
            }

            log("info", "Using default browser context", {
                domain,
                hasExtensions: true,
                contextType: "default",
            });

            return { context: defaultContext, cached: false, isDefault: true };
        }

        // No default context available, fall through to create new one
        log("warn", "No default context available, creating new context", {
            domain,
        });
    }

    // If persistence is enabled and we have a cached context
    if (usePersistence && contextCache.has(domain)) {
        const age = Date.now() - contextTimestamps.get(domain);

        // Check if context is still within TTL
        if (age < CONTEXT_TTL) {
            log("info", "Using cached browser context", {
                domain,
                age: `${age}ms`,
            });

            // Update cookies in existing context if provided
            if (cookies && Array.isArray(cookies) && cookies.length > 0) {
                await contextCache.get(domain).addCookies(cookies);
            }

            return {
                context: contextCache.get(domain),
                cached: true,
                isDefault: false,
            };
        } else {
            // TTL expired, close old context
            log("info", "Context TTL expired, creating new one", {
                domain,
                age: `${age}ms`,
            });
            try {
                await contextCache.get(domain).close();
            } catch (e) {
                log("warn", "Failed to close expired context", {
                    error: e.message,
                });
            }
            contextCache.delete(domain);
            contextTimestamps.delete(domain);
        }
    }

    // Create new context
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        userAgent: userAgent || undefined,
    });

    // Apply cookies if provided
    if (cookies && Array.isArray(cookies) && cookies.length > 0) {
        await context.addCookies(cookies);
    }

    // Cache if persistence is enabled
    if (usePersistence) {
        contextCache.set(domain, context);
        contextTimestamps.set(domain, Date.now());
        log("info", "Browser context cached", { domain });
    }

    return { context, cached: false, isDefault: false };
}

// Cleanup expired contexts periodically (every 5 minutes)
setInterval(() => {
    const now = Date.now();
    for (const [domain, timestamp] of contextTimestamps) {
        if (now - timestamp > CONTEXT_TTL) {
            log("info", "Cleaning up expired context", { domain });
            contextCache
                .get(domain)
                ?.close()
                .catch(() => {});
            contextCache.delete(domain);
            contextTimestamps.delete(domain);
        }
    }
}, 300000);

// Connect to Chrome container on startup
async function connectBrowser() {
    const cdpUrl = process.env.CHROME_CDP_URL || "http://chrome:9222";

    try {
        log("info", "Attempting to connect to Chrome", { cdpUrl });

        // Try to get the websocket endpoint first
        let wsEndpoint = cdpUrl;
        if (cdpUrl.startsWith("http://") || cdpUrl.startsWith("https://")) {
            try {
                const response = await fetch(`${cdpUrl}/json/version`);
                if (!response.ok) {
                    throw new Error(
                        `HTTP ${response.status}: ${response.statusText}`,
                    );
                }
                const versionData = await response.json();
                wsEndpoint = versionData.webSocketDebuggerUrl;
                log("info", "Retrieved WebSocket endpoint", { wsEndpoint });
            } catch (fetchError) {
                log(
                    "warn",
                    "Failed to fetch WebSocket endpoint, trying direct connection",
                    {
                        error: fetchError.message,
                    },
                );
            }
        }

        browser = await chromium.connectOverCDP(wsEndpoint, {
            slowMo: 100, // Add 100ms delay between operations for stability
        });

        reconnectAttempts = 0;
        log("info", "Successfully connected to Chrome", {
            endpoint: wsEndpoint,
        });

        // Handle browser disconnection
        browser.on("disconnected", async () => {
            log("warn", "Browser disconnected, attempting to reconnect");
            browser = null;

            if (reconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
                reconnectAttempts++;
                setTimeout(connectBrowser, 2000 * reconnectAttempts); // Exponential backoff
            } else {
                log("error", "Max reconnection attempts reached");
            }
        });

        return true;
    } catch (error) {
        log("error", "Failed to connect to Chrome", { error: error.message });

        if (reconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
            reconnectAttempts++;
            log("info", "Retrying connection", {
                attempt: reconnectAttempts,
                maxAttempts: MAX_RECONNECT_ATTEMPTS,
            });
            setTimeout(connectBrowser, 2000 * reconnectAttempts);
        }

        return false;
    }
}

// Middleware to check browser connection
function requireBrowser(req, res, next) {
    if (!browser || !browser.isConnected()) {
        return res.status(503).json({
            success: false,
            error: "Browser not connected",
            message:
                "Playwright worker is not connected to Chrome. Please wait for reconnection or check Chrome service.",
        });
    }
    next();
}

// Request logging middleware
app.use((req, res, next) => {
    const start = Date.now();
    res.on("finish", () => {
        const duration = Date.now() - start;
        log("info", "Request completed", {
            method: req.method,
            path: req.path,
            statusCode: res.statusCode,
            duration: `${duration}ms`,
        });
    });
    next();
});

// Health check endpoint
app.get("/health", (req, res) => {
    const connected = browser !== null && browser.isConnected();
    res.json({
        status: connected ? "ok" : "degraded",
        connected,
        reconnectAttempts,
        stealthEnabled: STEALTH_ENABLED,
        cachedContexts: contextCache.size,
        contextTTL: CONTEXT_TTL,
        timestamp: new Date().toISOString(),
    });
});

// Fetch URL endpoint
app.post("/fetch", requireBrowser, async (req, res) => {
    const {
        url,
        cookies,
        waitFor,
        timeout,
        screenshot,
        userAgent,
        usePersistence,
        useDefaultContext,
    } = req.body;

    if (!url) {
        return res.status(400).json({
            success: false,
            error: "Missing required parameter: url",
        });
    }

    let context = null;
    let page = null;
    let contextWasCached = false;
    let isDefaultContext = false;
    let shouldCloseContext = true;

    try {
        log("info", "Starting fetch", {
            url,
            hasCookies: !!cookies?.length,
            usePersistence,
            useDefaultContext,
            stealthEnabled: STEALTH_ENABLED,
        });

        // Extract domain from URL for caching
        const domain = new URL(url).hostname.replace(/^www\./, "");

        // Get or create browser context (with optional caching or default context)
        const contextResult = await getOrCreateContext(
            domain,
            cookies,
            userAgent,
            usePersistence,
            useDefaultContext,
        );
        context = contextResult.context;
        contextWasCached = contextResult.cached;
        isDefaultContext = contextResult.isDefault;

        // Never close the default context or cached contexts with persistence
        if (isDefaultContext || (usePersistence && contextWasCached)) {
            shouldCloseContext = false;
        }

        log("info", "Browser context ready", {
            domain,
            cached: contextWasCached,
            isDefault: isDefaultContext,
        });

        // Create new page
        page = await context.newPage();

        // Navigate to URL
        const navigationTimeout = timeout || 30000;
        await page.goto(url, {
            timeout: navigationTimeout,
            waitUntil: "domcontentloaded",
        });

        // Wait for network idle (optional, with timeout)
        if (waitFor === "networkidle") {
            try {
                await page.waitForLoadState("networkidle", { timeout: 5000 });
            } catch (e) {
                log("warn", "Network idle timeout, continuing anyway", { url });
            }
        }

        // Additional delay to allow dynamic content to render
        await page.waitForTimeout(3000);

        // Extract content
        const html = await page.content();
        const title = await page.title();
        const finalUrl = page.url(); // In case of redirects

        // Take screenshot if requested
        let screenshotBuffer = null;
        if (screenshot) {
            screenshotBuffer = await page.screenshot({
                type: "png",
                fullPage: false, // Only visible viewport
            });
        }

        // Get updated cookies
        const updatedCookies = await context.cookies();

        log("info", "Fetch completed successfully", {
            url,
            finalUrl,
            htmlLength: html.length,
            screenshotSize: screenshotBuffer ? screenshotBuffer.length : 0,
        });

        res.json({
            success: true,
            html,
            title,
            url: finalUrl,
            screenshot: screenshotBuffer
                ? screenshotBuffer.toString("base64")
                : null,
            cookies: updatedCookies,
            meta: {
                stealthEnabled: STEALTH_ENABLED,
                contextCached: contextWasCached,
                isDefaultContext: isDefaultContext,
            },
        });
    } catch (error) {
        log("error", "Fetch failed", {
            url,
            error: error.message,
            name: error.name,
        });

        res.status(500).json({
            success: false,
            error: error.message,
            errorType: error.name,
            url,
        });
    } finally {
        // Always clean up page
        if (page) {
            try {
                await page.close();
            } catch (e) {
                log("warn", "Failed to close page", { error: e.message });
            }
        }

        // Only close context if not using persistence or if it's a new context
        if (context && shouldCloseContext) {
            try {
                await context.close();
            } catch (e) {
                log("warn", "Failed to close context", { error: e.message });
            }
        }
    }
});

// Get cookies from browser context by domain
app.get("/cookies/:domain", requireBrowser, async (req, res) => {
    const { domain } = req.params;

    if (!domain) {
        return res.status(400).json({
            success: false,
            error: "Missing required parameter: domain",
        });
    }

    try {
        log("info", "Extracting cookies for domain", { domain });

        // Get all contexts (including default/persistent)
        const contexts = browser.contexts();

        if (contexts.length === 0) {
            return res.json({
                success: true,
                cookies: [],
                message: "No browser contexts available",
            });
        }

        // Get cookies from the first (default) context
        const allCookies = await contexts[0].cookies();

        // Filter cookies by domain (exact match or subdomain)
        const domainCookies = allCookies.filter((cookie) => {
            const cookieDomain = cookie.domain.startsWith(".")
                ? cookie.domain.substring(1)
                : cookie.domain;
            return (
                cookieDomain === domain ||
                cookieDomain === `www.${domain}` ||
                domain.endsWith(cookieDomain)
            );
        });

        log("info", "Cookies extracted", {
            domain,
            totalCookies: allCookies.length,
            domainCookies: domainCookies.length,
        });

        res.json({
            success: true,
            cookies: domainCookies,
            count: domainCookies.length,
        });
    } catch (error) {
        log("error", "Failed to extract cookies", {
            domain,
            error: error.message,
        });

        res.status(500).json({
            success: false,
            error: error.message,
        });
    }
});

// Get browser info
app.get("/browser/info", requireBrowser, async (req, res) => {
    try {
        const contexts = browser.contexts();
        const contextInfo = await Promise.all(
            contexts.map(async (ctx, idx) => {
                const pages = ctx.pages();
                return {
                    index: idx,
                    pageCount: pages.length,
                    pages: pages.map((p) => ({
                        url: p.url(),
                        title: p.title(),
                    })),
                };
            }),
        );

        res.json({
            success: true,
            connected: browser.isConnected(),
            contextCount: contexts.length,
            cachedContextCount: contextCache.size,
            contexts: contextInfo,
            stealthEnabled: STEALTH_ENABLED,
            contextTTL: CONTEXT_TTL,
        });
    } catch (error) {
        res.status(500).json({
            success: false,
            error: error.message,
        });
    }
});

// Clear cached contexts
app.post("/contexts/clear", requireBrowser, async (req, res) => {
    const { domain } = req.body;

    try {
        if (domain) {
            // Clear specific domain
            if (contextCache.has(domain)) {
                await contextCache.get(domain).close();
                contextCache.delete(domain);
                contextTimestamps.delete(domain);

                log("info", "Cleared context for domain", { domain });

                res.json({
                    success: true,
                    message: `Context cleared for domain: ${domain}`,
                });
            } else {
                res.json({
                    success: false,
                    message: `No cached context found for domain: ${domain}`,
                });
            }
        } else {
            // Clear all contexts
            const domains = Array.from(contextCache.keys());

            for (const [domain, context] of contextCache) {
                try {
                    await context.close();
                } catch (e) {
                    log("warn", "Failed to close context during clear", {
                        domain,
                        error: e.message,
                    });
                }
            }

            contextCache.clear();
            contextTimestamps.clear();

            log("info", "Cleared all contexts", { count: domains.length });

            res.json({
                success: true,
                message: `Cleared ${domains.length} cached contexts`,
                domains,
            });
        }
    } catch (error) {
        log("error", "Failed to clear contexts", { error: error.message });

        res.status(500).json({
            success: false,
            error: error.message,
        });
    }
});

// Error handler
app.use((err, req, res, next) => {
    log("error", "Unhandled error", {
        error: err.message,
        stack: err.stack,
    });

    res.status(500).json({
        success: false,
        error: "Internal server error",
        message: err.message,
    });
});

// Start server
const PORT = process.env.PORT || 3000;
app.listen(PORT, async () => {
    log("info", "Playwright worker starting", { port: PORT });
    await connectBrowser();
    log("info", "Playwright worker ready", { port: PORT });
});

// Graceful shutdown
process.on("SIGTERM", async () => {
    log("info", "SIGTERM received, shutting down gracefully");
    if (browser) {
        await browser.close();
    }
    process.exit(0);
});

process.on("SIGINT", async () => {
    log("info", "SIGINT received, shutting down gracefully");
    if (browser) {
        await browser.close();
    }
    process.exit(0);
});
