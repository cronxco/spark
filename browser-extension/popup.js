const MAX_CAPTURE_BYTES = 5 * 1024 * 1024;

const captureButton = document.querySelector("#capture");
const settingsButton = document.querySelector("#settings");
const status = document.querySelector("#status");

function setStatus(message, state = "") {
    status.textContent = message;
    status.dataset.state = state;
}

function captureRenderedDocument() {
    const clone = document.documentElement.cloneNode(true);
    const canonicalUrl = document.querySelector('link[rel="canonical"]')?.href;
    const capturedUrl = /^https?:/.test(canonicalUrl ?? "")
        ? canonicalUrl
        : window.location.href;

    clone
        .querySelectorAll(
            "script, style, noscript, template, iframe, object, embed, canvas, svg",
        )
        .forEach((element) => element.remove());

    return {
        url: capturedUrl,
        title: document.title,
        html: `<!doctype html>${clone.outerHTML}`,
    };
}

async function getSettings() {
    return chrome.storage.local.get(["sparkBaseUrl", "sparkToken"]);
}

async function capturePage() {
    captureButton.disabled = true;
    setStatus("Reading the current page…");

    try {
        const { sparkBaseUrl, sparkToken } = await getSettings();
        if (!sparkBaseUrl || !sparkToken) {
            throw new Error("Configure your Spark URL and API token first.");
        }

        const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
        if (!tab?.id || !/^https?:/.test(tab.url ?? "")) {
            throw new Error("This browser page cannot be captured.");
        }

        const [injection] = await chrome.scripting.executeScript({
            target: { tabId: tab.id },
            func: captureRenderedDocument,
        });
        const page = injection?.result;

        if (!page?.html) {
            throw new Error("Chrome could not read this page.");
        }

        const captureBytes = new Blob([page.html]).size;
        if (captureBytes > MAX_CAPTURE_BYTES) {
            throw new Error(
                `This page is too large to capture (${(captureBytes / 1024 / 1024).toFixed(1)} MB).`,
            );
        }

        setStatus("Sending the captured article to Spark…");
        const response = await fetch(`${sparkBaseUrl}/api/v1/bookmarks/capture`, {
            method: "POST",
            headers: {
                Accept: "application/json",
                Authorization: `Bearer ${sparkToken}`,
                "Content-Type": "application/json",
            },
            body: JSON.stringify(page),
        });
        const result = await response.json().catch(() => ({}));

        if (!response.ok) {
            const detail = Object.values(result.errors ?? {}).flat()[0];
            throw new Error(detail || result.message || `Spark returned ${response.status}.`);
        }

        const message = result.state === "recaptured" ? "Updated in Spark." : "Saved to Spark.";
        setStatus(message, "success");
    } catch (error) {
        setStatus(error.message || "The page could not be saved.", "error");
    } finally {
        captureButton.disabled = false;
    }
}

captureButton.addEventListener("click", capturePage);
settingsButton.addEventListener("click", () => chrome.runtime.openOptionsPage());

getSettings().then(({ sparkBaseUrl, sparkToken }) => {
    if (!sparkBaseUrl || !sparkToken) {
        setStatus("Configure the extension before your first capture.");
    }
});
