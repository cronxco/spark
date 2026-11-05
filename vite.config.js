import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";
import { sentryVitePlugin } from "@sentry/vite-plugin";

// Source maps are only generated (and uploaded to Sentry) when an auth token is
// present — i.e. during the deploy build. Local `npm run build` stays lean.
const sentryUpload = Boolean(process.env.SENTRY_AUTH_TOKEN);

export default defineConfig({
    build: {
        sourcemap: sentryUpload ? "hidden" : false,
    },
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js"],
            refresh: true,
        }),
        tailwindcss(),
        sentryUpload &&
            sentryVitePlugin({
                org: process.env.SENTRY_ORG,
                project: process.env.SENTRY_PROJECT,
                authToken: process.env.SENTRY_AUTH_TOKEN,
                release: { name: process.env.SENTRY_RELEASE },
                sourcemaps: {
                    filesToDeleteAfterUpload: ["./public/build/**/*.map"],
                },
                // Source-map upload is best-effort — never fail a deploy build because
                // Sentry was briefly unreachable.
                errorHandler: (err) => {
                    console.warn(`[sentry-vite-plugin] ${err.message}`);
                },
            }),
    ],
    server: {
        cors: true,
    },
});
