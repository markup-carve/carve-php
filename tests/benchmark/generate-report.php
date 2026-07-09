#!/usr/bin/env php
<?php
/**
 * carve-php Benchmark Report Generator
 *
 * Generates beautiful HTML reports from benchmark results.
 *
 * Usage:
 *   php tests/benchmark/generate-report.php [results.json] [--output=report.html]
 */

declare(strict_types=1);

$options = getopt('', ['output:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
carve-php Benchmark Report Generator

Usage: php generate-report.php [results.json] [--output=report.html]

Options:
  --output=FILE   Output HTML file (default: benchmark-report.html)
  --help          Show this help

If no results.json is provided, will look in results/ directory for the most recent one.

HELP;
    exit(0);
}

$outputFile = $options['output'] ?? 'benchmark-report.html';
$inputFile = $argv[1] ?? null;

// Find input file
if (!$inputFile) {
    $resultsDir = __DIR__ . '/results';
    if (is_dir($resultsDir)) {
        $files = glob($resultsDir . '/php-benchmark-*.json');
        if ($files) {
            usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));
            $inputFile = $files[0];
        }
    }
}

if (!$inputFile || !file_exists($inputFile)) {
    echo "No benchmark results found. Run benchmarks first.\n";
    exit(1);
}

$results = json_decode(file_get_contents($inputFile), true);
if (!$results) {
    echo "Invalid JSON in {$inputFile}\n";
    exit(1);
}

function formatMs(float $ms): string
{
    if ($ms < 1) {
        return sprintf('%.2f µs', $ms * 1000);
    }
    if ($ms < 1000) {
        return sprintf('%.2f ms', $ms);
    }

    return sprintf('%.2f s', $ms / 1000);
}

function formatSize(int $bytes): string
{
    if ($bytes < 1024) {
        return "{$bytes} B";
    }
    if ($bytes < 1024 * 1024) {
        return sprintf('%.1f KB', $bytes / 1024);
    }

    return sprintf('%.1f MB', $bytes / (1024 * 1024));
}

// Generate chart data: mean conversion time per fixture
$chartData = [];
$baselineFixture = 'medium';

if (isset($results['conversion'])) {
    foreach ($results['conversion'] as $name => $data) {
        if (isset($data['stats']['mean'])) {
            $chartData[$name] = $data['stats']['mean'];
        }
    }
}

// Build HTML
$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>carve-php Benchmark Report</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4f46e5;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        header {
            text-align: center;
            margin-bottom: 3rem;
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            color: var(--text-muted);
            font-size: 1.1rem;
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .card h2 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 0.75rem 1rem;
            text-align: right;
            border-bottom: 1px solid var(--border);
        }

        th {
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        td:first-child, th:first-child {
            text-align: left;
        }

        tr:hover {
            background: var(--bg);
        }

        .metric {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .metric-fast {
            background: #dcfce7;
            color: #166534;
        }

        .metric-slow {
            background: #fee2e2;
            color: #991b1b;
        }

        .chart-container {
            position: relative;
            height: 400px;
            margin: 1rem 0;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .stat-card {
            text-align: center;
            padding: 1.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .badge {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-primary { background: #e0e7ff; color: #3730a3; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }

        footer {
            text-align: center;
            padding: 2rem;
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            h1 {
                font-size: 1.75rem;
            }

            table {
                font-size: 0.875rem;
            }

            th, td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>carve-php Benchmark Report</h1>
            <p class="subtitle">PHP performance results</p>
        </header>

HTML;

// Summary cards
$phpMean = $results['conversion'][$baselineFixture]['stats']['mean'] ?? null;
$phpThroughput = $results['conversion'][$baselineFixture]['throughput_bps'] ?? null;

$html .= '<div class="grid">';

if ($phpMean) {
    $html .= sprintf('
        <div class="card stat-card">
            <div class="stat-value">%s</div>
            <div class="stat-label">Mean Time (medium doc)</div>
        </div>
    ', formatMs($phpMean));
}

if ($phpThroughput) {
    $html .= sprintf('
        <div class="card stat-card">
            <div class="stat-value">%s/s</div>
            <div class="stat-label">Throughput</div>
        </div>
    ', formatSize((int)$phpThroughput));
}

$html .= '</div>';

// Performance comparison chart
if ($chartData) {
    $labels = json_encode(array_keys($chartData));
    $values = json_encode(array_values($chartData));

    $html .= <<<HTML
        <div class="card">
            <h2>Conversion Time by Fixture (Mean)</h2>
            <div class="chart-container">
                <canvas id="performanceChart"></canvas>
            </div>
        </div>
HTML;
}

// Conversion Results Table
if (isset($results['conversion'])) {
    $html .= '<div class="card"><h2>Conversion Results</h2><table>';
    $html .= '<tr><th>Fixture</th><th>Size</th><th>Mean</th><th>Median</th><th>P95</th><th>Throughput</th></tr>';

    foreach ($results['conversion'] as $name => $data) {
        $stats = $data['stats'];
        $html .= sprintf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s/s</td></tr>',
            htmlspecialchars($name),
            formatSize($data['size_bytes']),
            formatMs($stats['mean']),
            formatMs($stats['median']),
            formatMs($stats['p95']),
            formatSize((int)$data['throughput_bps']),
        );
    }

    $html .= '</table></div>';
}

// Profile Results Table
if (isset($results['profiles'])) {
    $html .= '<div class="card"><h2>Profile Results (medium fixture)</h2><table>';
    $html .= '<tr><th>Profile</th><th>Mean</th><th>Median</th><th>P95</th></tr>';

    foreach ($results['profiles'] as $name => $stats) {
        $html .= sprintf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            htmlspecialchars($name),
            formatMs($stats['mean']),
            formatMs($stats['median']),
            formatMs($stats['p95']),
        );
    }

    $html .= '</table></div>';
}

// Metadata
$html .= '<div class="card"><h2>Environment</h2><table>';
$html .= '<tr><th>Runtime</th><th>Version</th></tr>';

if (isset($results['meta'])) {
    $html .= sprintf('<tr><td>PHP</td><td>%s</td></tr>', htmlspecialchars($results['meta']['php_version']));
}

$html .= '</table></div>';

// Chart JavaScript
if ($chartData) {
    $html .= <<<HTML
    <script>
        const ctx = document.getElementById('performanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: {$labels},
                datasets: [{
                    label: 'Mean Time (ms)',
                    data: {$values},
                    backgroundColor: [
                        'rgba(79, 70, 229, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(168, 85, 247, 0.8)',
                        'rgba(6, 182, 212, 0.8)'
                    ],
                    borderColor: [
                        'rgb(79, 70, 229)',
                        'rgb(245, 158, 11)',
                        'rgb(34, 197, 94)',
                        'rgb(239, 68, 68)',
                        'rgb(168, 85, 247)',
                        'rgb(6, 182, 212)'
                    ],
                    borderWidth: 2,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.raw.toFixed(2) + ' ms';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Time (ms)'
                        }
                    }
                }
            }
        });
    </script>
HTML;
}

$generatedAt = $results['meta']['timestamp'] ?? date('c');

$html .= <<<HTML
        <footer>
            <p>Generated on {$generatedAt} by the carve-php Benchmark Suite</p>
        </footer>
    </div>
</body>
</html>
HTML;

// Write output
$outputPath = __DIR__ . '/' . $outputFile;
file_put_contents($outputPath, $html);

echo "Report generated: {$outputPath}\n";
