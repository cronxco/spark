# Save to Spark Chrome extension

Captures the rendered DOM of the current tab and sends it to Spark as a
one-time bookmark. Because capture happens in Chrome, pages unlocked by the
browser's existing login session can be archived without sending site cookies
to Spark.

## Install locally

1. In Spark, create a personal access token with only the `bookmark:write`
   ability.
2. Open `chrome://extensions`, enable **Developer mode**, and choose
   **Load unpacked**.
3. Select this `browser-extension` directory.
4. Open the extension's **Options**, enter the Spark origin and token, then
   choose **Save and test connection**.

Use HTTPS for a remotely hosted Spark instance. The token is stored in
`chrome.storage.local` and is sent only to the Spark origin explicitly granted
in the options page.

## Capture flow

The extension removes executable and presentation-only elements from a cloned
DOM, then posts up to 5 MB of HTML to `POST /api/v1/bookmarks/capture`. Spark
runs its existing Readability extraction and dispatches
`ProcessFetchedContent`, preserving the normal Fetch revision, summary,
embedding, and task-pipeline behaviour without requesting the source URL.
