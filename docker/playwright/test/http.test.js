const { before, after, describe, test } = require("node:test");
const assert = require("node:assert/strict");
const { spawn } = require("node:child_process");
const { once } = require("node:events");
const net = require("node:net");
const path = require("node:path");
const { setTimeout: delay } = require("node:timers/promises");

describe("worker HTTP contract", { timeout: 30000 }, () => {
    let worker;
    let baseUrl;
    let output = "";

    before(async () => {
        const socket = net.createServer();
        socket.listen(0, "127.0.0.1");
        await once(socket, "listening");
        const port = socket.address().port;
        await new Promise((resolve) => socket.close(resolve));
        baseUrl = `http://127.0.0.1:${port}`;

        worker = spawn(
            process.execPath,
            [
                "--require",
                path.join(__dirname, "fixtures/browser-stub.js"),
                path.join(__dirname, "../index.js"),
            ],
            {
                env: {
                    ...process.env,
                    PORT: String(port),
                    STEALTH_ENABLED: "false",
                    CHROME_CDP_URL: "ws://unused.test",
                    FETCH_URL_SAFETY_ALLOWED_HOSTS: "localhost",
                },
                stdio: ["ignore", "pipe", "pipe"],
            },
        );
        worker.stdout.on("data", (data) => (output += data));
        worker.stderr.on("data", (data) => (output += data));

        for (let attempt = 0; attempt < 100; attempt++) {
            if (worker.exitCode !== null) {
                throw new Error(`Worker exited during startup: ${output}`);
            }
            try {
                const response = await fetch(`${baseUrl}/health`);
                if ((await response.json()).connected) return;
            } catch {}
            await delay(50);
        }
        throw new Error(`Worker did not become ready: ${output}`);
    });

    after(async () => {
        if (worker && worker.exitCode === null) {
            const exited = once(worker, "exit");
            worker.kill("SIGTERM");
            await exited;
        }
    });

    test("health and browser information routes respond", async () => {
        const health = await fetch(`${baseUrl}/health`);
        assert.equal(health.status, 200);
        assert.equal((await health.json()).status, "ok");
        const info = await fetch(`${baseUrl}/browser/info`);
        assert.equal(info.status, 200);
        assert.equal((await info.json()).contextCount, 1);
    });

    test("named route parameters select the requested domain's cookies", async () => {
        const response = await fetch(`${baseUrl}/cookies/example.com`);
        assert.equal(response.status, 200);
        const body = await response.json();
        assert.equal(body.count, 1);
        assert.equal(body.cookies[0].domain, ".example.com");
    });

    test("JSON requests without a URL are rejected", async () => {
        const response = await fetch(`${baseUrl}/fetch`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({}),
        });
        assert.equal(response.status, 400);
        assert.equal((await response.json()).success, false);
    });

    test("an asynchronous browser failure returns JSON and leaves the worker responsive", async () => {
        const response = await fetch(`${baseUrl}/fetch`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ url: "http://localhost/article" }),
        });
        assert.equal(response.status, 500);
        assert.equal(
            (await response.json()).error,
            "Browser context creation failed",
        );
        assert.equal((await fetch(`${baseUrl}/health`)).status, 200);
    });
});
