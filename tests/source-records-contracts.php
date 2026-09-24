<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\SourceRecords;

$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = "0 @S1@ SOUR\n1 TITL Old title\n1 AUTH Author\n1 NOTE Keep this note\n1 DATA\n2 TEXT Unrelated";
$modified = SourceRecords::modify($source, ['title' => 'New title']);
check($modified['changed'], 'Source title changes');
check(str_contains($modified['gedcom'], '1 TITL New title'), 'Updated title is written');
check(str_contains($modified['gedcom'], "1 DATA\n2 TEXT Unrelated"), 'Unrelated source data is preserved');

$target = "0 @I1@ INDI\n1 NAME Example\n1 BIRT\n2 DATE 1 JAN 1900";
$direct = SourceRecords::addCitation($target, 'S1', '', 'p. 4', 'Direct note');
check($direct['changed'], 'Direct citation is added');
check(str_contains($direct['gedcom'], "1 SOUR @S1@\n2 PAGE p. 4"), 'Direct citation has page');
$event = SourceRecords::addCitation($direct['gedcom'], 'S1', 'BIRT', 'p. 5', 'Birth note');
check($event['changed'], 'Event citation is added');
check(str_contains($event['gedcom'], "1 BIRT\n2 DATE 1 JAN 1900\n2 SOUR @S1@"), 'Event citation is nested under event');
$duplicate = SourceRecords::addCitation($event['gedcom'], 'S1', 'BIRT', 'p. 5', 'Birth note');
check(!$duplicate['changed'], 'Duplicate citation is a no-op');

$citations = SourceRecords::citations($event['gedcom']);
check(count($citations) === 2, 'Both citations are readable');
$directCitation = array_values(array_filter($citations, static fn (array $citation): bool => $citation['event'] === ''))[0] ?? [];
$eventCitation = array_values(array_filter($citations, static fn (array $citation): bool => $citation['event'] === 'BIRT'))[0] ?? [];
check(($directCitation['page'] ?? '') === 'p. 4', 'Direct citation is parsed');
check(($eventCitation['note'] ?? '') === 'Birth note', 'Event citation is parsed');

foreach (['CreateSource', 'ModifySource', 'GetSources', 'AddSourceCitation', 'GetCitations'] as $handler) {
    check(is_file(__DIR__ . '/../src/Http/RequestHandlers/' . $handler . '.php'), $handler . ' handler exists');
}

echo "Source record contract checks passed: {$checks}\n";
