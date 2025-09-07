import * as Sentry from "@sentry/browser";
import Tagify from "@yaireo/tagify";
import "@yaireo/tagify/dist/tagify.css";

Sentry.init({
    dsn: import.meta.env.VITE_SENTRY_DSN || window.SENTRY_DSN || undefined,
    integrations: [
        Sentry.browserTracingIntegration({
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
    tracesSampleRate: Number(
        import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? 0.2,
    ),
    enableAutoSessionTracking: true,
    release: window.SENTRY_RELEASE || undefined,
    environment: window.SENTRY_ENVIRONMENT || undefined,
});

// Expose Tagify globally for inline Alpine/Blade scripts
window.Tagify = Tagify;

function escapeHtml(str) {
    return String(str)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#39;");
}

function inferTagTypeFromValue(value) {
    const v = String(value || "").trim();
    // Heuristics: type_prefix or type:label → take the type segment
    if (v.includes("_")) {
        const [maybeType] = v.split("_");
        if (maybeType && /^[a-z0-9-]+$/i.test(maybeType))
            return maybeType.toLowerCase();
    }
    if (v.includes(":")) {
        const [maybeType] = v.split(":");
        if (maybeType && /^[a-z0-9-]+$/i.test(maybeType))
            return maybeType.toLowerCase();
    }
    return undefined;
}

function stripKnownPrefixForDisplay(value) {
    const v = String(value || "");
    // Hide prefixes like category_foo or person:bar (only the first segment + _ or :) is removed)
    if (v.includes("_")) {
        const [maybeType, rest] = v.split("_", 2);
        if (maybeType && rest && /^[a-z0-9-]+$/i.test(maybeType)) return rest;
    }
    if (v.includes(":")) {
        const [maybeType, rest] = v.split(":", 2);
        if (maybeType && rest && /^[a-z0-9-]+$/i.test(maybeType)) return rest;
    }
    return v;
}

function iconForTagType(type) {
    switch ((type || "").toLowerCase()) {
        case "music_album":
            return "💿";
        case "music_artist":
            return "🎤";
        case "spotify_context":
            return "🎧";
        case "album":
            return "💿";
        case "artist":
            return "🎤";
        case "category":
            return "🗂️";
        case "project":
            return "📁";
        case "person":
            return "👤";
        case "task":
            return "✅";
        case "location":
            return "📍";
        case "spark":
            return "⚡";
        case "emoji":
            return "";
        default:
            return "";
    }
}

function resolveDisplayType(tagData) {
    return tagData?.type || inferTagTypeFromValue(tagData?.value) || undefined;
}

function isEmojiOnly(str) {
    const s = String(str || "").trim();
    if (!s) return false;
    // Extended pictographic with optional joiners/variation selectors
    try {
        const re =
            /^\p{Extended_Pictographic}(?:[\uFE0F\uFE0E])?(?:\u200D\p{Extended_Pictographic}(?:[\uFE0F\uFE0E])?)*$/u;
        return re.test(s);
    } catch (_) {
        // Fallback: simple emoji block heuristic
        const fallback =
            /^(?:[\u2600-\u27BF]|[\uD83C-\uDBFF][\uDC00-\uDFFF])+$/;
        return fallback.test(s);
    }
}

function initializeTagifyInput(input) {
    if (!window.Tagify || !input || input._tagifyInstance) {
        return;
    }

    try {
        const initialId = input.getAttribute("data-initial");
        // Support both data-whitelist (used in Blade) and legacy data-suggestions-id
        const suggestionsId =
            input.getAttribute("data-whitelist") ||
            input.getAttribute("data-suggestions-id");
        const initialEl = initialId ? document.getElementById(initialId) : null;
        const suggestionsEl = suggestionsId
            ? document.getElementById(suggestionsId)
            : null;
        const whitelist = suggestionsEl
            ? JSON.parse(suggestionsEl.textContent || "[]")
            : [];
        const initial = initialEl
            ? JSON.parse(initialEl.textContent || "[]")
            : [];

        const tagify = new window.Tagify(input, {
            whitelist,
            tagTextProp: "value",
            enforceWhitelist: false,
            editTags: { keepInvalid: false },
            transformTag(tagData) {
                const raw =
                    tagData?.value ?? tagData?.name ?? tagData?.text ?? "";
                // Normalize to a plain string in case value arrives as an array/object
                tagData.value = Array.isArray(raw)
                    ? String(raw.join(" "))
                    : String(raw);
                // Preserve/derive a visual type hint for template use without affecting stored value
                tagData.type =
                    tagData.type ||
                    inferTagTypeFromValue(tagData.value) ||
                    undefined;
                // Store displayLabel separately (for UI only), keep tagData.value intact for storage
                tagData.displayLabel = stripKnownPrefixForDisplay(
                    tagData.value,
                );
                return tagData;
            },
            dropdown: {
                enabled: 1,
                maxItems: 20,
                closeOnSelect: true,
                highlightFirst: true,
            },
            originalInputValueFormat(values) {
                // Keep a simple CSV for underlying value to avoid JSON showing up anywhere accidentally
                return values
                    .map((v) => String(v?.value ?? ""))
                    ?.filter(Boolean)
                    .join(",");
            },
            templates: {
                tag(tagData) {
                    const icon = iconForTagType(resolveDisplayType(tagData));
                    const val = escapeHtml(
                        tagData?.displayLabel ?? tagData?.value ?? "",
                    );
                    const cls = this.settings.classNames;
                    const text = icon ? `${icon} ${val}` : val;
                    return `
            <tag title="${val}" contenteditable='false' spellcheck='false' tabIndex='-1' class="${cls.tag} ${tagData.class ? tagData.class : ""}" ${this.getAttributes(tagData)}>
              <x title='' class="${cls.tagX}" role='button' aria-label='remove tag'></x>
              <div>
                <span class="${cls.tagText}">${text}</span>
              </div>
            </tag>
          `;
                },
            },
        });
        input._tagifyInstance = tagify;

        // Ensure wrapper fills width and receives theme variables
        try {
            const scope = tagify?.DOM?.scope;
            if (scope) {
                scope.style.width = "100%";
                // Inline variable theming to win over load-order of tagify.css
                scope.style.setProperty(
                    "--tags-border-color",
                    "var(--color-base-300)",
                );
                scope.style.setProperty(
                    "--tags-hover-border-color",
                    "var(--color-base-300)",
                );
                scope.style.setProperty(
                    "--tags-focus-border-color",
                    "var(--color-primary)",
                );
                scope.style.setProperty(
                    "--tags-disabled-bg",
                    "var(--color-base-200)",
                );
                scope.style.setProperty(
                    "--tag-border-radius",
                    "var(--radius-field)",
                );
                scope.style.setProperty("--tag-bg", "var(--color-base-200)");
                scope.style.setProperty("--tag-hover", "var(--color-base-300)");
                scope.style.setProperty(
                    "--tag-text-color",
                    "var(--color-base-content)",
                );
                scope.style.setProperty(
                    "--tag-text-color--edit",
                    "var(--color-base-content)",
                );
                scope.style.setProperty("--tag-pad", ".15rem .4rem");
                scope.style.setProperty("--tag--min-width", "0ch");
                scope.style.setProperty("--tag--max-width", "32ch");
                scope.style.setProperty("--tag-inset-shadow-size", "0px");
                scope.style.setProperty(
                    "--tag-invalid-color",
                    "var(--color-error)",
                );
                scope.style.setProperty(
                    "--tag-invalid-bg",
                    "color-mix(in oklab, var(--color-error) 10%, var(--color-base-100))",
                );
                scope.style.setProperty(
                    "--tag-remove-btn-color",
                    "var(--color-base-content)",
                );
                scope.style.setProperty("--tag-remove-btn-bg", "transparent");
                scope.style.setProperty(
                    "--tag-remove-btn-bg--hover",
                    "color-mix(in oklab, var(--color-error) 16%, transparent)",
                );
                scope.style.setProperty(
                    "--tag-remove-bg",
                    "color-mix(in oklab, var(--color-error) 8%, transparent)",
                );
                scope.style.setProperty(
                    "--input-color",
                    "var(--color-base-content)",
                );
                scope.style.setProperty(
                    "--placeholder-color",
                    "color-mix(in oklab, var(--color-base-content) 45%, transparent)",
                );
                scope.style.setProperty(
                    "--placeholder-color-focus",
                    "color-mix(in oklab, var(--color-base-content) 55%, transparent)",
                );
                scope.style.setProperty("--loader-size", ".9em");
                scope.style.setProperty("--readonly-striped", "0");
                scope.style.setProperty(
                    "--tagify-dd-color-primary",
                    "var(--color-primary)",
                );
                scope.style.setProperty(
                    "--tagify-dd-text-color",
                    "var(--color-base-content)",
                );
                scope.style.setProperty(
                    "--tagify-dd-bg-color",
                    "var(--color-base-100)",
                );
                scope.style.setProperty(
                    "--tagify-dd-item--hidden-duration",
                    ".15s",
                );
                scope.style.setProperty("--tagify-dd-item-pad", ".4rem .5rem");
                scope.style.setProperty("--tagify-dd-max-height", "18rem");
            }

            const inputEl = tagify?.DOM?.input;
            if (inputEl) {
                // Help Tagify grow and avoid extra phantom line
                inputEl.style.minWidth = "1px";
                inputEl.style.whiteSpace = "normal";
                inputEl.style.display = "inline-flex";
                inputEl.style.flexWrap = "wrap";
                inputEl.style.alignItems = "flex-start";
            }

            const tagsEl = scope?.querySelector?.(".tagify__tags");
            if (tagsEl) {
                tagsEl.style.display = "flex";
                tagsEl.style.flexWrap = "wrap";
                tagsEl.style.gap = "0.25rem";
            }
        } catch (_) {
            // noop
        }

        if (Array.isArray(initial) && initial.length) {
            const normalized = initial
                .map((v) => {
                    if (typeof v === "string") {
                        const s = v.trim();
                        return s ? s : null;
                    }
                    if (v && typeof v === "object") {
                        const value = String(
                            v.value ?? v.name ?? v.text ?? "",
                        ).trim();
                        if (!value) return null;
                        const type = v.type ? String(v.type) : undefined;
                        return type ? { value, type } : { value };
                    }
                    return null;
                })
                .filter(Boolean);
            if (normalized.length) tagify.addTags(normalized);
        }

        const root = input.closest("[wire\\:id]");
        const livewireId = root ? root.getAttribute("wire:id") : null;

        tagify.on("add", (e) => {
            const data = e?.detail?.data || {};
            const rawValue = String(data?.value ?? "").trim();
            const explicitType = data?.type ? String(data.type) : undefined;
            let name = rawValue;
            let typeToSend = explicitType;

            // If user typed type:label or type_label, extract type for storage while keeping original label as value
            if (
                !typeToSend &&
                (rawValue.includes(":") || rawValue.includes("_"))
            ) {
                const match = rawValue.match(/^([A-Za-z0-9-]+)[_:](.+)$/);
                if (match) {
                    typeToSend = match[1].toLowerCase();
                    name = match[2].trim();
                }
            }

            if (!name || !livewireId || !window.Livewire) return;
            // Only set default type for brand-new tags that have no explicit or inferred type AND are not in whitelist
            const isWhitelisted =
                Array.isArray(whitelist) &&
                whitelist.some((w) => {
                    const wv = typeof w === "string" ? w : (w?.value ?? "");
                    return String(wv).toLowerCase() === name.toLowerCase();
                });
            let finalType = typeToSend;
            if (!finalType && !isWhitelisted) {
                finalType = isEmojiOnly(name) ? "emoji" : "spark";
            }
            window.Livewire.find(livewireId)?.call("addTag", name, finalType);
        });

        tagify.on("remove", (e) => {
            const data = e?.detail?.data || {};
            const name = String(data?.value ?? "").trim();
            const type = data?.type ? String(data.type) : null;
            if (!name || !livewireId || !window.Livewire) return;
            window.Livewire.find(livewireId)?.call("removeTag", name, type);
        });

        // Nudge Tagify to recalc its size after initial tags render
        setTimeout(() => {
            try {
                tagify?.dropdown?.hide?.();
                // Trigger a reflow to ensure wrapping takes effect
                const scope = tagify?.DOM?.scope;
                if (scope) {
                    scope.style.height = "auto";
                }
            } catch (_) {}
        }, 0);
    } catch (err) {
        // eslint-disable-next-line no-console
        console.error("Tagify init failed", err);
    }
}

function initializeAllTagify() {
    document
        .querySelectorAll("input[data-tagify]")
        ?.forEach((input) => initializeTagifyInput(input));
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeAllTagify);
} else {
    initializeAllTagify();
}

document.addEventListener("livewire:init", initializeAllTagify);
document.addEventListener("livewire:navigated", initializeAllTagify);
