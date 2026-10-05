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

$source = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/DownloadMedia.php');
$media = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/Media.php');
$permissions = file_get_contents(__DIR__ . '/../src/Http/Middleware/McpToolPermission.php');
$dispatcher = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/McpTool.php');
$bridge = file_get_contents(__DIR__ . '/../bin/webtrees-mcp.mjs');

check(str_contains($source, 'content-url'), 'Server returns a signed content URL');
check(str_contains($source, "'required' => ['filename', 'bytes', 'sha256']"), 'Minimal required download fields; base64 optional');
check(str_contains($source, 'content-base64'), 'Legacy base64 fallback still documented');
check(str_contains($media, "hash('sha256'") || str_contains($media, 'sha256Stream'), 'Server returns checksum');
check(str_contains($permissions, 'PATH_DOWNLOAD_MEDIA'), 'Download tool read permission');
check(str_contains($dispatcher, 'DownloadMedia::class'), 'Download tool dispatch');
check(str_contains($bridge, "request.params.name === 'download-media'"), 'Bridge intercepts download tool');
check(str_contains($bridge, "payload['content-url']"), 'Bridge fetches the signed URL');
check(str_contains($bridge, "'local-path'"), 'Bridge returns local path');

echo "Download-media contract checks passed: {$checks}\n";
