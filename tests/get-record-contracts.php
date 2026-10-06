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
$media = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/Media.php');
$search = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/SearchGeneral.php');
$format = file_get_contents(__DIR__ . '/../src/Http/Parameter/GedcomFormat.php');
check(str_contains($source, 'full_gedcom_requires_confirmation'), 'Full GEDCOM safety error');
check(str_contains($source, 'allow-full-gedcom'), 'Full GEDCOM allow flag');
check(str_contains($source, 'I_UNDERSTAND_FULL_GEDCOM'), 'Full GEDCOM confirmation');
check(str_contains($source, "FORMAT_GEDCOM &&"), 'Guard applies to full GEDCOM only');
check(str_contains($format, 'requires allow-full-gedcom=true'), 'Format documentation');
check(!str_contains($source, "'outputSchema' => ["), 'GEDCOM record output is not falsely declared as an object schema');
check(str_contains($media, 'json_decode($body, true)') && str_contains($media, '$payload[\'error\'][\'message\']'), 'Media access errors preserve upstream response messages');
check(str_contains($search, "'gedcom_data' => [") && str_contains($search, "['type' => 'string']"), 'Search result schema permits GEDCOM text');

echo "Get-record contract checks passed: {$checks}\n";
