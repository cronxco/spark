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
        sentry({
            org: process.env.SENTRY_ORG || undefined,
            project: process.env.SENTRY_PROJECT || undefined,
            authToken: process.env.SENTRY_AUTH_TOKEN || undefined,
            sourcemaps: {
                assets: ['./public/build/**'],
            },
            release: {
                name: process.env.SENTRY_RELEASE || undefined,
            },
        }),
    ],
    server: {
        cors: true,
    },
});