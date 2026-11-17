<?php

declare(strict_types=1);

require __DIR__.'/compare.php';

$temporary = tempnam(sys_get_temp_dir(), 'symsvel-k6-');
if ($temporary === false) {
    throw new RuntimeException('Cannot create temporary test summary.');
}
try {
    file_put_contents($temporary, json_encode(['metrics' => [
        'http_reqs' => ['values' => ['rate' => 34.5, 'count' => 345]],
        'http_req_duration' => ['values' => ['p(95)' => 120.1, 'p(99)' => 299.2]],
        'http_req_failed' => ['values' => ['rate' => 0.01]],
        'symsvel_server_error_rate' => ['values' => ['rate' => 0]],
        'symsvel_http_429' => ['values' => ['count' => 0]],
    ]], JSON_THROW_ON_ERROR));
    $table = renderLoadTestComparison([$temporary]);
    if (!str_contains($table, '| 345 | 34.5 | 120.1 | 299.2 | 1.00 | 0.00 | 0 |')) {
        throw new RuntimeException('Metric comparison is incorrect.');
    }
    if (!str_contains($table, 'Req./s') || !str_contains($table, 'p95 (ms)')) {
        throw new RuntimeException('Missing comparison headings.');
    }

    file_put_contents($temporary, '{"metrics":{}}');
    if (!str_contains(renderLoadTestComparison([$temporary]), '| n/a | n/a |')) {
        throw new RuntimeException('Missing metrics must appear as n/a.');
    }

    file_put_contents($temporary, '{}');
    try {
        renderLoadTestComparison([$temporary]);
        throw new RuntimeException('Invalid JSON shape was not rejected.');
    } catch (InvalidArgumentException) {
        // Correct: invalid metrics shape is rejected.
    }
} finally {
    unlink($temporary);
}

echo "k6 summary report tests passed.\n";
