import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtemp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import semanticRelease from "semantic-release";

const config = JSON.parse(
    await readFile(new URL("../../.releaserc", import.meta.url), "utf8"),
);
const directory = await mkdtemp(path.join(os.tmpdir(), "spark-release-check-"));
const remote = path.join(directory, "remote.git");
const working = path.join(directory, "working");
const env = Object.fromEntries(
    Object.entries(process.env).filter(([key]) => !key.startsWith("GITHUB_")),
);
env.CI = "false";
const git = (...args) =>
    execFileSync("git", args, { cwd: working, env, encoding: "utf8" }).trim();

try {
    await mkdir(working);
    execFileSync("git", ["init", "--bare", remote], { env, stdio: "pipe" });
    git("init", "--initial-branch=main");
    git("config", "user.name", "Release compatibility test");
    git("config", "user.email", "release-test@example.invalid");
    git("remote", "add", "origin", pathToFileURL(remote).href);
    git("commit", "--allow-empty", "-m", ":tada: Initial fixture release");
    git("tag", "v1.0.0");
    git("commit", "--allow-empty", "-m", ":bug: Fix fixture behavior");
    git("push", "--tags", "origin", "main");

    // Exercise the application's version rules and VERSION.txt hook against a
    // local Git remote. Publishing transport requires release credentials.
    const plugins = config.plugins
        .filter((plugin) =>
            (Array.isArray(plugin) ? plugin[0] : plugin) !==
            "@semantic-release/github",
        )
        .map((plugin) => {
            const [name, options] = Array.isArray(plugin)
                ? plugin
                : [plugin, {}];
            return [fileURLToPath(import.meta.resolve(name)), options];
        });

    const result = await semanticRelease(
        {
            ...config,
            plugins,
            branches: ["main"],
            repositoryUrl: pathToFileURL(remote).href,
            dryRun: true,
            ci: false,
        },
        { cwd: working, env },
    );
    assert.ok(result, "The fixture must produce a release preview");
    assert.equal(result.nextRelease.version, "1.0.1");
    assert.equal(
        (await readFile(path.join(working, "VERSION.txt"), "utf8")).trim(),
        "1.0.1",
    );
    assert.equal(git("tag", "--list"), "v1.0.0");
    console.log("Release preview and VERSION.txt hook passed without publishing");
} finally {
    await rm(directory, { recursive: true, force: true });
}
