import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";
import sentry from "@sentry/vite-plugin";

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        process.env.SENTRY_AUTH_TOKEN &&
        sentry({
            org: process.env.SENTRY_ORG,
            project: process.env.SENTRY_PROJECT,
            authToken: process.env.SENTRY_AUTH_TOKEN,
            include: ['./public/build'],
            release: process.env.SENTRY_RELEASE,
            dryRun: !process.env.CI,   // avoid local upload failures
        }),
    ],
    server: {
        cors: true,
    },
});