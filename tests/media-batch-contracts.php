<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}

$media = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/Media.php');
$handler = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/UploadMediaBatch.php');
$bridge = file_get_contents(__DIR__ . '/../bin/webtrees-mcp.mjs');
$permissions = file_get_contents(__DIR__ . '/../src/Http/Middleware/McpToolPermission.php');
check(str_contains($media, 'executeBatch'), 'Media batch execution');
check(str_contains($media, 'updateRecord($target->gedcom() . $links'), 'Single target update');
check(str_contains($handler, 'PATH_UPLOAD_MEDIA_BATCH'), 'Batch MCP tool');
check(str_contains($bridge, 'uploadBatch'), 'Bridge batch transfer');
check(str_contains($permissions, 'PATH_UPLOAD_MEDIA_BATCH'), 'Batch write permission');

echo "Media-batch contract checks passed: {$checks}\n";
