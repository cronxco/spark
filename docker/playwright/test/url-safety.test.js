const test = require("node:test");
const assert = require("node:assert/strict");

const { isPublicIp, truncateHtml, validateUrl } = require("../url-safety");

test("identifies public and private IP addresses", () => {
    assert.equal(isPublicIp("93.184.216.34"), true);
    assert.equal(isPublicIp("127.0.0.1"), false);
    assert.equal(isPublicIp("169.254.169.254"), false);
    assert.equal(isPublicIp("::1"), false);
    assert.equal(isPublicIp("fc00::1"), false);
});

test("rejects unsafe URLs before browser navigation", async () => {
    await assert.rejects(
        () => validateUrl("ftp://example.com"),
        /unsupported scheme/,
    );
    await assert.rejects(
        () => validateUrl("http://user:pass@example.com"),
        /credentials/,
    );
    await assert.rejects(
        () => validateUrl("http://localhost/admin"),
        /blocked host/,
    );
    await assert.rejects(
        () => validateUrl("http://127.0.0.1/admin"),
        /non-public/,
    );
});

test("honours the explicitly configured host allowlist", async () => {
    await assert.doesNotReject(() =>
        validateUrl("http://localhost/health", new Set(["localhost"])),
    );
});

test("truncates HTML at a UTF-8 character boundary", () => {
    const result = truncateHtml("abc😀def", 6);

    assert.equal(result.html, "abc");
    assert.equal(result.htmlBytes, 10);
    assert.equal(result.returnedHtmlBytes, 3);
    assert.equal(result.htmlTruncated, true);
});
