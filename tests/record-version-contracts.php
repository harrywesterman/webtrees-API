<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\RecordVersion;

$checks = 0;
function check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }

$first = RecordVersion::fromGedcom("0 @I1@ INDI\r\n1 NAME Example");
$second = RecordVersion::fromGedcom("0 @I1@ INDI\n1 NAME Example");
$changed = RecordVersion::fromGedcom("0 @I1@ INDI\n1 NAME Changed");
check($first['version'] === $first['hash'], 'Version and hash are the same stable value');
check($first['hash'] === $second['hash'], 'Line endings do not change the hash');
check($first['hash'] !== $changed['hash'], 'Changed GEDCOM gets a new hash');

$modify = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/ModifyRecord.php');
$delete = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/DeleteRecord.php');
$verify = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/VerifyWrite.php');
check(str_contains($modify, "'pending' => true"), 'Modify receipt identifies pending state');
check(str_contains($modify, 'RecordVersion::fromGedcom'), 'Modify receipt includes record hash');
check(str_contains($delete, "'state' => 'pending_delete'"), 'Delete receipt identifies pending deletion');
check(str_contains($verify, "'state' => 'applied'"), 'Verify-write reports applied state');
check(str_contains($verify, "'state' => 'deleted'"), 'Verify-write reports deleted state');

echo "Record version contract checks passed: {$checks}\n";
