const form = document.querySelector("#settings-form");
const urlInput = document.querySelector("#spark-url");
const tokenInput = document.querySelector("#spark-token");
const status = document.querySelector("#status");

function setStatus(message, state = "") {
    status.textContent = message;
    status.dataset.state = state;
}

function normalizeBaseUrl(value) {
    const url = new URL(value);
    if (!["http:", "https:"].includes(url.protocol)) {
        throw new Error("Spark must use an HTTP or HTTPS URL.");
    }

    return url.origin;
}

async function loadSettings() {
    const { sparkBaseUrl = "", sparkToken = "" } = await chrome.storage.local.get([
        "sparkBaseUrl",
        "sparkToken",
    ]);
    urlInput.value = sparkBaseUrl;
    tokenInput.value = sparkToken;
}

form.addEventListener("submit", async (event) => {
    event.preventDefault();
    setStatus("Checking the connection…");

    try {
        const sparkBaseUrl = normalizeBaseUrl(urlInput.value.trim());
        const sparkToken = tokenInput.value.trim().replace(/^Bearer\s+/i, "");
        const originPermission = `${sparkBaseUrl}/*`;

        const granted = await chrome.permissions.request({ origins: [originPermission] });
        if (!granted) {
            throw new Error("Chrome needs permission to connect to this Spark origin.");
        }

        const response = await fetch(`${sparkBaseUrl}/api/user`, {
            headers: {
                Accept: "application/json",
                Authorization: `Bearer ${sparkToken}`,
            },
        });

        if (!response.ok) {
            throw new Error(
                response.status === 401
                    ? "Spark rejected this API token."
                    : `Spark returned ${response.status} while testing the connection.`,
            );
        }

        await chrome.storage.local.set({ sparkBaseUrl, sparkToken });
        urlInput.value = sparkBaseUrl;
        tokenInput.value = sparkToken;
        setStatus("Connected to Spark.", "success");
    } catch (error) {
        setStatus(error.message || "The connection could not be saved.", "error");
    }
});

loadSettings();
