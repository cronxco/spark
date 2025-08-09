import * as Sentry from '@sentry/browser';
import { BrowserTracing } from '@sentry/browser';

Sentry.init({
  dsn: import.meta.env.VITE_SENTRY_DSN || window.SENTRY_DSN || undefined,
  integrations: [
    new BrowserTracing({
      traceFetch: true,
      traceXHR: true,
      // Adjust targets to your domains and API paths
      tracePropagationTargets: [
        /^https?:\/\/[\w.-]*localhost(?::\d+)?\/?/,
        /^https?:\/\/[^/]*your-domain\.com\/?/,
        /^\//,
      ],
    }),
  ],
  tracesSampleRate: Number(import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? 0.2),
  enableAutoSessionTracking: true,
  release: window.SENTRY_RELEASE || undefined,
  environment: window.SENTRY_ENVIRONMENT || undefined,
});