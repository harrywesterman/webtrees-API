<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$source = file_get_contents(__DIR__ . '/../src/Helpers/functions.php');
$bridge = file_get_contents(__DIR__ . '/../bin/webtrees-mcp.mjs');
$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}

check(str_contains($source, "'token_invalid'"), 'Unauthorized responses expose token_invalid');
check(str_contains($source, "'scope_missing'"), 'Forbidden responses expose scope_missing');
check(str_contains($source, "'protected_links_would_be_removed'"), 'Protected-link conflicts have a stable code');
check(str_contains($source, "'pending_conflict'"), 'Pending conflicts have a stable code');
check(str_contains($source, "'inline_upload_too_large'"), '413 responses have a stable upload code');
check(str_contains($bridge, 'remote.code'), 'The bridge reads structured remote error codes');
check(str_contains($bridge, 'http_${response.status}'), 'The bridge includes a fallback HTTP error code');

echo "Error code contract checks passed: {$checks}\n";
