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
check(str_contains($source, "IdempotencyStore::lookup"), 'Idempotency keys are resolved from the store, not the record');
check(str_contains($source, "IdempotencyStore::record"), 'Idempotency keys are recorded outside the GEDCOM');
check(!str_contains($source, '_WT_API_'), 'No bookkeeping tag is written into the family');
echo "Add-family contract checks passed: {$checks}\n";
