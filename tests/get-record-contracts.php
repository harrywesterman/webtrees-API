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

$source = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/GetRecord.php');
$format = file_get_contents(__DIR__ . '/../src/Http/Parameter/GedcomFormat.php');
check(str_contains($source, 'full_gedcom_requires_confirmation'), 'Full GEDCOM safety error');
check(str_contains($source, 'allow-full-gedcom'), 'Full GEDCOM allow flag');
check(str_contains($source, 'I_UNDERSTAND_FULL_GEDCOM'), 'Full GEDCOM confirmation');
check(str_contains($source, "FORMAT_GEDCOM &&"), 'Guard applies to full GEDCOM only');
check(str_contains($format, 'requires allow-full-gedcom=true'), 'Format documentation');

echo "Get-record contract checks passed: {$checks}\n";
