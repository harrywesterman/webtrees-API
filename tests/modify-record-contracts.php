<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\GedcomRecordMutation;

$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$before = "0 @I1@ INDI\n1 NAME Test\n1 FAMS @F1@\n2 NOTE Spouse\n1 FAMC @F2@\n1 OBJE @M1@\n2 DATA Scan\n1 CHIL @I2@";
$submitted = "0 @I1@ INDI\n1 NAME Changed";
$preserved = GedcomRecordMutation::preserveProtectedLinks($before, $submitted);

check($preserved['preserved'] === ['FAMS @F1@', 'FAMC @F2@', 'OBJE @M1@', 'CHIL @I2@'], 'Preserves all protected link types');
check(str_contains($preserved['gedcom'], "1 FAMS @F1@\n2 NOTE Spouse"), 'Preserves subordinate lines');
check(str_contains($preserved['gedcom'], "1 OBJE @M1@\n2 DATA Scan"), 'Preserves media subordinate lines');
check(GedcomRecordMutation::removedProtectedLinks($before, $submitted) === ['FAMS @F1@', 'FAMC @F2@', 'OBJE @M1@', 'CHIL @I2@'], 'Reports removed protected links');
check(GedcomRecordMutation::removedProtectedLinks($before, $preserved['gedcom']) === [], 'Preserved result has no removed links');

$explicit = "0 @I1@ INDI\n1 NAME Changed\n1 FAMS @F1@";
check(GedcomRecordMutation::removedProtectedLinks($before, $explicit) === ['FAMC @F2@', 'OBJE @M1@', 'CHIL @I2@'], 'Explicit partial removal remains visible');

$modifySource = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/ModifyRecord.php');
check(str_contains($modifySource, "'remove-protected-links'"), 'Explicit removal option is exposed');
check(str_contains($modifySource, "'dry-run'"), 'Dry-run option is exposed');

echo "Modify-record contract checks passed: {$checks}\n";
