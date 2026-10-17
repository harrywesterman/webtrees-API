<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$checks = 0;
function check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }

$handler = static fn (string $file): string => file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/' . $file);

// Issue #21: the check lives in one validator and every raw-GEDCOM write path
// uses it with the record type it is about to create.
$validator = file_get_contents(__DIR__ . '/../src/Http/Validation/QueryParamValidator.php');
check(str_contains($validator, 'string $record_type = \'\''), 'validateGedcomRecord() accepts the record type');
check(str_contains($validator, 'GedcomStructure::problems($record_type, $gedcom)'), 'The validator asks GedcomStructure');
check(str_contains($validator, 'STATUS_UNPROCESSABLE_ENTITY'), 'An unsupported structure is a 422');

foreach (['AddChildToFamily.php', 'AddChildToIndividual.php', 'AddParentToIndividual.php', 'AddSpouseToFamily.php', 'AddSpouseToIndividual.php'] as $file) {
    check(str_contains($handler($file), "validateGedcomRecord(\$gedcom, true, 'INDI')"), $file . ' validates against INDI structures');
}
check(str_contains($handler('AddUnlinkedRecord.php'), 'validateGedcomRecord($gedcom, true, $record_type)'), 'add-unlinked-record validates against its own record type');

foreach (['CreateRecords.php', 'ModifyRecord.php', 'AddFamily.php'] as $file) {
    check(str_contains($handler($file), 'GedcomStructure::problems('), $file . ' validates raw GEDCOM');
    check(str_contains($handler($file), 'GedcomStructure::message('), $file . ' reports the offending paths');
}
check(str_contains($handler('ModifyRecord.php'), 'GedcomStructure::problems($record->tag(), $modified_gedcom, $record->gedcom())'), 'modify-record tolerates what the record already carries');
check(str_contains($handler('CreateRecords.php'), 'throw new DomainException(\'Record \' . $id'), 'A batch rejection names the record');

$structure = file_get_contents(__DIR__ . '/../src/Helpers/GedcomStructure.php');
check(str_contains($structure, 'UnknownElement'), 'UnknownElement is the test webtrees uses to draw a red line');
check(str_contains($structure, 'Registry::elementFactory()'), 'The tag registry comes from webtrees, not a copied allowlist');
check(str_contains($structure, 'catch (Throwable)'), 'An unavailable registry fails open instead of rejecting everything');

echo "GEDCOM validation contract checks passed: {$checks}\n";
