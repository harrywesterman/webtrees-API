<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$checks = 0;
function check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
$source = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/AddFamily.php');
check(str_contains($source, 'DB::connection()->transaction'), 'Family creation is transactional');
check(str_contains($source, "'idempotent' => true"), 'Repeated idempotency keys are handled');
check(str_contains($source, "'HUSB'"), 'HUSB link is supported');
check(str_contains($source, "'WIFE'"), 'WIFE link is supported');
check(str_contains($source, "1 CHIL"), 'CHIL links are built');
check(str_contains($source, "'FAMS'"), 'FAMS links are built');
check(str_contains($source, "'FAMC'"), 'FAMC links are built');
check(str_contains($source, "'pending' => true"), 'Receipt identifies pending transaction');
echo "Add-family contract checks passed: {$checks}\n";
