<?php

declare(strict_types=1);

/**
 * Compare local k6 --summary-export JSON files.
 * Pure PHP 8.4, no dependencies and no network calls.
 */
function metric(array $summary, string $name, string $field): ?float
{
    $value = $summary['metrics'][$name]['values'][$field] ?? null;

    return is_int($value) || is_float($value) ? (float) $value : null;
}

function formatted(?float $value, int $precision = 1): string
{
    return $value === null ? 'n/a' : number_format($value, $precision, '.', '');
}

/** @return list<string> */
function loadTestSummaryRow(string $filename): array
{
    if (!is_file($filename) || !is_readable($filename)) {
        throw new InvalidArgumentException('Unreadable summary: '.$filename);
    }
    $content = file_get_contents($filename);
    if (!is_string($content) || strlen($content) > 5_000_000) {
        throw new InvalidArgumentException('Invalid or oversized k6 summary.');
    }
    $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['metrics']) || !is_array($data['metrics'])) {
        throw new InvalidArgumentException('Invalid k6 summary.');
    }

    $failures = metric($data, 'http_req_failed', 'rate');
    $serverFailures = metric($data, 'symsvel_server_error_rate', 'rate');
    $label = preg_replace('/-summary$/', '', pathinfo($filename, PATHINFO_FILENAME));

    return [
        str_replace('|', '/', (string) $label),
        formatted(metric($data, 'http_reqs', 'count'), 0),
        formatted(metric($data, 'http_reqs', 'rate')),
        formatted(metric($data, 'http_req_duration', 'p(95)')),
        formatted(metric($data, 'http_req_duration', 'p(99)')),
        formatted($failures === null ? null : 100 * $failures, 2),
        formatted($serverFailures === null ? null : 100 * $serverFailures, 2),
        formatted(metric($data, 'symsvel_http_429', 'count'), 0),
    ];
}

/** @param list<string> $files */
function renderLoadTestComparison(array $files): string
{
    if ($files === []) {
        throw new InvalidArgumentException('Provide at least one k6 summary JSON file.');
    }
    $head = ['Test', 'HTTP req.', 'Req./s', 'p95 (ms)', 'p99 (ms)', 'Échecs %', 'Srv/conn %', 'HTTP 429'];
    $lines = [
        '| '.implode(' | ', $head).' |',
        '|'.implode('|', array_fill(0, count($head), '---')).'|',
    ];
    foreach ($files as $file) {
        $lines[] = '| '.implode(' | ', loadTestSummaryRow($file)).' |';
    }

    return implode("\n", $lines);
}

if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        echo renderLoadTestComparison(array_slice($argv, 1))."\n";
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage()."\n");
        exit(2);
    }
}
