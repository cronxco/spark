const dns = require("node:dns").promises;
const net = require("node:net");

const BLOCKED_HOSTS = new Set(["localhost", "metadata.google.internal"]);

function configuredAllowedHosts(
    value = process.env.FETCH_URL_SAFETY_ALLOWED_HOSTS || "",
) {
    return new Set(
        value
            .split(",")
            .map((host) => host.trim().toLowerCase())
            .filter(Boolean),
    );
}

function isPublicIp(ip) {
    const version = net.isIP(ip);

    if (version === 4) {
        const [a, b] = ip.split(".").map(Number);

        return !(
            a === 0 ||
            a === 10 ||
            a === 127 ||
            a >= 224 ||
            (a === 100 && b >= 64 && b <= 127) ||
            (a === 169 && b === 254) ||
            (a === 172 && b >= 16 && b <= 31) ||
            (a === 192 && b === 0) ||
            (a === 192 && b === 168) ||
            (a === 198 && (b === 18 || b === 19))
        );
    }

    if (version === 6) {
        const normalized = ip.toLowerCase();

        return !(
            normalized === "::" ||
            normalized === "::1" ||
            normalized.startsWith("fc") ||
            normalized.startsWith("fd") ||
            /^fe[89ab]/.test(normalized) ||
            normalized.startsWith("::ffff:127.") ||
            normalized.startsWith("::ffff:10.") ||
            normalized.startsWith("::ffff:192.168.") ||
            /^::ffff:172\.(1[6-9]|2\d|3[01])\./.test(normalized)
        );
    }

    return false;
}

async function resolveHost(host) {
    if (net.isIP(host)) {
        return [host];
    }

    const results = await Promise.allSettled([
        dns.resolve4(host),
        dns.resolve6(host),
    ]);

    const addresses = results.flatMap((result) =>
        result.status === "fulfilled" ? result.value : [],
    );

    return [...new Set(addresses)];
}

async function validateUrl(url, allowedHosts = configuredAllowedHosts()) {
    let parsed;

    try {
        parsed = new URL(url);
    } catch {
        throw new Error("Unsafe URL: malformed URL");
    }

    if (!["http:", "https:"].includes(parsed.protocol)) {
        throw new Error("Unsafe URL: unsupported scheme");
    }

    if (parsed.username || parsed.password) {
        throw new Error("Unsafe URL: credentials in URL");
    }

    const host = parsed.hostname.toLowerCase();
    if (allowedHosts.has(host)) {
        return;
    }

    if (BLOCKED_HOSTS.has(host)) {
        throw new Error("Unsafe URL: blocked host");
    }

    const addresses = await resolveHost(host);
    if (addresses.length === 0) {
        throw new Error("Unsafe URL: host did not resolve");
    }

    for (const address of addresses) {
        if (!isPublicIp(address)) {
            throw new Error(`Unsafe URL: non-public address (${address})`);
        }
    }
}

function truncateHtml(html, maxBytes) {
    const bytes = Buffer.from(html, "utf8");
    const htmlBytes = bytes.length;

    if (!Number.isInteger(maxBytes) || maxBytes <= 0 || htmlBytes <= maxBytes) {
        return {
            html,
            htmlBytes,
            returnedHtmlBytes: htmlBytes,
            htmlTruncated: false,
        };
    }

    let end = maxBytes;
    while (end > 0 && (bytes[end] & 0xc0) === 0x80) {
        end--;
    }

    const truncated = bytes.subarray(0, end).toString("utf8");

    return {
        html: truncated,
        htmlBytes,
        returnedHtmlBytes: Buffer.byteLength(truncated, "utf8"),
        htmlTruncated: true,
    };
}

module.exports = {
    configuredAllowedHosts,
    isPublicIp,
    truncateHtml,
    validateUrl,
};
