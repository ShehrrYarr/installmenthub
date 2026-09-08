import {
    Chart,
    LineController,
    BarController,
    LineElement,
    BarElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';

Chart.register(
    LineController,
    BarController,
    LineElement,
    BarElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Legend,
    Filler,
);

const rupees = (value) => 'Rs. ' + Math.round(value).toLocaleString('en-PK');

/** Abbreviated for axis ticks, where full amounts would collide. */
const shortRupees = (value) => {
    const abs = Math.abs(value);

    if (abs >= 10000000) return (value / 10000000).toFixed(1).replace(/\.0$/, '') + 'cr';
    if (abs >= 100000) return (value / 100000).toFixed(1).replace(/\.0$/, '') + 'L';
    if (abs >= 1000) return (value / 1000).toFixed(0) + 'k';

    return String(value);
};

const buildConfig = (payload) => {
    const dark = document.documentElement.classList.contains('dark');
    const grid = dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
    const text = dark ? 'rgba(255,255,255,0.6)' : 'rgba(0,0,0,0.55)';

    return {
        type: payload.type,
        data: {
            labels: payload.labels,
            datasets: payload.datasets.map((dataset) => ({
                label: dataset.label,
                data: dataset.data,
                borderColor: dataset.color,
                backgroundColor: payload.type === 'bar' ? dataset.color : dataset.fill,
                fill: payload.type === 'line',
                tension: 0.35,
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                borderRadius: payload.type === 'bar' ? 4 : 0,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: payload.datasets.length > 1,
                    position: 'bottom',
                    labels: { color: text, boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 16 },
                },
                tooltip: {
                    callbacks: {
                        label: (item) => ` ${item.dataset.label}: ${rupees(item.parsed.y)}`,
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: text, maxRotation: 0, autoSkipPadding: 16 } },
                y: {
                    beginAtZero: true,
                    grid: { color: grid },
                    border: { display: false },
                    ticks: { color: text, callback: shortRupees, maxTicksLimit: 5 },
                },
            },
        },
    };
};

document.addEventListener('alpine:init', () => {
    // Wraps one Chart.js canvas on the dashboard. The chart lives inside a
    // wire:ignore block so Livewire's DOM morphing never touches the canvas —
    // instead, switching the date range dispatches `charts-updated` with fresh
    // series, and this swaps the data in place.
    Alpine.data('dashboardChart', (initial) => {
        // Deliberately a closure variable, not a property on the returned
        // object: Alpine deep-proxies its own reactive data, and proxying a
        // Chart.js instance (deeply nested, self-referencing) blows the call
        // stack and corrupts its internals on the next update.
        let chart = null;

        return {
            init() {
                chart = new Chart(this.$refs.canvas, buildConfig(initial));

                // Livewire's SPA navigation swaps the page out from under the
                // canvas — release it rather than leaking the instance.
                document.addEventListener('livewire:navigating', () => {
                    chart?.destroy();
                    chart = null;
                }, { once: true });
            },

            update(payload) {
                if (! chart || ! payload) return;

                chart.data.labels = payload.labels;
                chart.data.datasets.forEach((dataset, index) => {
                    dataset.data = payload.datasets[index]?.data ?? [];
                });
                chart.update();
            },
        };
    });
});
