<?php

declare(strict_types=1);

// The PHP side of the planner trace harness: formats each input and prints one JSON line per repair round, in the
// format of js-trace.mjs. Usage: php php-trace.php <input.crv | rows.jsonl>... > trace.jsonl

require __DIR__ . '/../../../vendor/autoload.php';

use MarkupCarve\Carve\Test\Writer\PlannerTrace\PlannerTrace;

$files = is_array($_SERVER['argv'] ?? null) ? array_slice($_SERVER['argv'], 1) : [];
foreach ($files as $file) {
    foreach (is_string($file) ? PlannerTrace::inputs($file) : [] as $id => $input) {
        echo json_encode(['input' => $id] + PlannerTrace::php($input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
}
