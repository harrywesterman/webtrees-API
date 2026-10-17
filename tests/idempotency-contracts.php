<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$checks = 0;
function check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }

$create = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/CreateRecords.php');
$family = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/AddFamily.php');
$store = file_get_contents(__DIR__ . '/../src/Helpers/IdempotencyStore.php');

// Issue #20: idempotency is transport metadata and must not end up in the
// GEDCOM; webtrees renders unknown tags as errors on the record page.
foreach (['create-records' => $create, 'add-family' => $family] as $tool => $source) {
    check(!str_contains($source, '_WT_API_'), $tool . ' writes no bookkeeping tag into the record');
    check(str_contains($source, 'IdempotencyStore::ensureTable()'), $tool . ' creates the store before writing');
    check(str_contains($source, 'IdempotencyStore::lookup('), $tool . ' resolves a replay from the store');
    check(str_contains($source, 'IdempotencyStore::record('), $tool . ' records the key outside the GEDCOM');
    check(str_contains($source, 'IdempotencyStore::stillLive('), $tool . ' refuses to replay a cancelled batch');
    check(str_contains($source, "DB::table('gedcom')->where('gedcom_id', \$tree->id())->lockForUpdate()"), $tool . ' serializes writers on the tree row');
}

check(str_contains($store, "TABLE = 'webtrees_api_idempotency'"), 'The store has its own table');
check(str_contains($store, 'hasTable('), 'The store is created only when it is missing');
check(str_contains($store, '->lockForUpdate()'), 'Store reads take the writing transaction lock');
check(str_contains($store, 'RETENTION_DAYS'), 'Old keys are pruned');
check(str_contains($create, "'Part of the prior batch was cancelled; inspect it before retrying with a new key.'"), 'Cancelled batches keep their 409');

echo "Idempotency contract checks passed: {$checks}\n";
