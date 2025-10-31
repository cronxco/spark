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

    // Helper to convert HSL to RGB
    const hslToRgb = (hsl) => {
        const [h, s, l] = hsl.split(" ").map((v) => parseFloat(v));
        const a = (s * Math.min(l, 1 - l)) / 100;
        const f = (n) => {
            const k = (n + h / 30) % 12;
            const color = l - a * Math.max(Math.min(k - 3, 9 - k, 1), -1);
            return Math.round(255 * color);
        };
        return `${f(0)}, ${f(8)}, ${f(4)}`;
    };

    return {
        primary: hslToRgb(style.getPropertyValue("--p")),
        secondary: hslToRgb(style.getPropertyValue("--s")),
        accent: hslToRgb(style.getPropertyValue("--a")),
        success: hslToRgb(style.getPropertyValue("--su")),
        warning: hslToRgb(style.getPropertyValue("--wa")),
        error: hslToRgb(style.getPropertyValue("--er")),
        info: hslToRgb(style.getPropertyValue("--in")),
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
                    color: "rgba(0, 0, 0, 0.05)",
                },
                ticks: {
                    font: {
                        family: "Nunito, sans-serif",
                        size: 11,
                    },
                },
            },
            y: {
                grid: {
                    display: true,
                    color: "rgba(0, 0, 0, 0.05)",
                },
                ticks: {
                    font: {
                        family: "Nunito, sans-serif",
                        size: 11,
                    },
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
    const colors = getThemeColors();

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
