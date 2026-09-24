<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$checks = 0;
function check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
$store = file_get_contents(__DIR__ . '/../src/Http/Validation/MediaChunkStore.php');
$handler = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/UploadMediaStatus.php');
check(str_contains($store, 'public function status'), 'Server exposes upload status');
check(str_contains($store, "'uncertain' => " . '$state' . "['status'] === 'committing'"), 'Status exposes uncertain commit state');
check(str_contains($store, "'response'"), 'Completed upload status exposes durable receipt');
check(str_contains($handler, 'UploadMediaStatus'), 'Status MCP handler exists');
check(str_contains($handler, 'never start a new upload ID blindly'), 'Status tool documents safe recovery');
echo "Upload status contract checks passed: {$checks}\n";
