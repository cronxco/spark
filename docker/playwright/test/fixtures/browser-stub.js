const browser = {
    isConnected: () => true,
    on: () => {},
    close: async () => {},
    contexts: () => [
        {
            cookies: async () => [
                { name: "session", value: "test", domain: ".example.com" },
                { name: "other", value: "test", domain: "unrelated.test" },
            ],
            pages: () => [],
        },
    ],
    newContext: async () => {
        throw new Error("Browser context creation failed");
    },
};

require.cache[require.resolve("playwright-extra")] = {
    id: require.resolve("playwright-extra"),
    filename: require.resolve("playwright-extra"),
    loaded: true,
    exports: { chromium: { connectOverCDP: async () => browser } },
};
