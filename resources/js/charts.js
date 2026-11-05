import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Title,
    Tooltip,
    Legend,
    Filler,
} from "chart.js";

// Register only the components we need
Chart.register(
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Title,
    Tooltip,
    Legend,
    Filler,
);

/**
 * Get current daisyUI theme colors from CSS variables
 * These colors adapt automatically to light/dark mode
 */
export function getThemeColors() {
    const style = getComputedStyle(document.documentElement);

    // Helper to convert hex to RGB string
    const hexToRgb = (hex) => {
        if (!hex) return null;
        // Remove # if present
        hex = hex.trim().replace("#", "");
        if (hex.length === 3) {
            // Expand shorthand form (e.g. "03F") to full form (e.g. "0033FF")
            hex = hex
                .split("")
                .map((c) => c + c)
                .join("");
        }
        if (hex.length === 6) {
            const r = parseInt(hex.slice(0, 2), 16);
            const g = parseInt(hex.slice(2, 4), 16);
            const b = parseInt(hex.slice(4, 6), 16);
            return `${r}, ${g}, ${b}`;
        }
        return null;
    };

    // Helper to get color from CSS variable (supports hex and rgb)
    const getColorValue = (varName) => {
        const value = style.getPropertyValue(varName).trim();
        if (!value) return null;

        // If it's already rgb/rgba, extract the values
        if (value.startsWith("rgb")) {
            const match = value.match(/\d+/g);
            if (match && match.length >= 3) {
                return `${match[0]}, ${match[1]}, ${match[2]}`;
            }
        }

        // If it's hex, convert it
        if (value.startsWith("#")) {
            return hexToRgb(value);
        }

        // Try as HSL (for daisyUI compatibility)
        const hslMatch = value.match(
            /^(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)%?\s+(\d+(?:\.\d+)?)%?$/,
        );
        if (hslMatch) {
            const [, h, s, l] = hslMatch.map(parseFloat);
            const a = (s * Math.min(l, 100 - l)) / 10000;
            const f = (n) => {
                const k = (n + h / 30) % 12;
                const color =
                    l / 100 - a * Math.max(Math.min(k - 3, 9 - k, 1), -1);
                return Math.round(255 * color);
            };
            return `${f(0)}, ${f(8)}, ${f(4)}`;
        }

        return null;
    };

    return {
        primary:
            getColorValue("--color-primary") ||
            getColorValue("--p") ||
            "255, 191, 0",
        secondary:
            getColorValue("--color-secondary") ||
            getColorValue("--s") ||
            "128, 128, 128",
        accent:
            getColorValue("--color-accent") ||
            getColorValue("--a") ||
            "255, 191, 0",
        success:
            getColorValue("--color-success") ||
            getColorValue("--su") ||
            "0, 128, 0",
        warning:
            getColorValue("--color-warning") ||
            getColorValue("--wa") ||
            "255, 165, 0",
        error:
            getColorValue("--color-error") ||
            getColorValue("--er") ||
            "255, 0, 0",
        info:
            getColorValue("--color-info") ||
            getColorValue("--in") ||
            "0, 128, 255",
        baseContent:
            getColorValue("--color-base-content") ||
            getColorValue("--bc") ||
            "245, 245, 245",
    };
}

/**
 * Get common chart defaults with theme-aware styling
 */
export function getChartDefaults() {
    const colors = getThemeColors();

    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: "index",
            intersect: false,
        },
        plugins: {
            legend: {
                display: true,
                position: "bottom",
                labels: {
                    usePointStyle: true,
                    padding: 15,
                    font: {
                        family: "Nunito, sans-serif",
                        size: 12,
                    },
                    color: `rgb(${colors.baseContent})`,
                },
            },
            tooltip: {
                backgroundColor: `rgba(${colors.primary}, 0.9)`,
                titleFont: {
                    family: "Nunito, sans-serif",
                    size: 14,
                },
                bodyFont: {
                    family: "Nunito, sans-serif",
                    size: 13,
                },
                padding: 12,
                cornerRadius: 8,
            },
        },
        scales: {
            x: {
                grid: {
                    display: true,
                    color: `rgba(${colors.baseContent}, 0.1)`,
                },
                ticks: {
                    font: {
                        family: "Nunito, sans-serif",
                        size: 11,
                    },
                    color: `rgba(${colors.baseContent}, 0.7)`,
                },
                title: {
                    color: `rgba(${colors.baseContent}, 0.7)`,
                },
            },
            y: {
                grid: {
                    display: true,
                    color: `rgba(${colors.baseContent}, 0.1)`,
                },
                ticks: {
                    font: {
                        family: "Nunito, sans-serif",
                        size: 11,
                    },
                    color: `rgba(${colors.baseContent}, 0.7)`,
                },
                title: {
                    color: `rgba(${colors.baseContent}, 0.7)`,
                },
            },
        },
    };
}

/**
 * Create a line chart with common configuration
 */
export function createLineChart(ctx, data, options = {}) {
    const defaults = getChartDefaults();

    const config = {
        type: "line",
        data: data,
        options: {
            ...defaults,
            ...options,
            plugins: {
                ...defaults.plugins,
                ...(options.plugins || {}),
            },
            scales: {
                ...defaults.scales,
                ...(options.scales || {}),
            },
        },
    };

    return new Chart(ctx, config);
}

// Export Chart and utilities to window for Alpine components
window.Chart = Chart;
window.chartUtils = {
    getThemeColors,
    getChartDefaults,
    createLineChart,
};
