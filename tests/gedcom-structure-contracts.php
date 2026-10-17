<?php

/**
 * Pre-flight GEDCOM structure validation against the registry webtrees itself
 * uses to decide whether a tag is an "Unrecognized GEDCOM code".
 */
declare(strict_types=1);

$root = getenv('WEBTREES_TEST_ROOT');
if (!$root) throw new RuntimeException('Set WEBTREES_TEST_ROOT to an unpacked webtrees release.');
require $root . '/vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Fisharebest\Webtrees\Factories\ElementFactory;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Jefferson49\Webtrees\Module\WebtreesApi\Helpers\GedcomStructure;

// The labels in the tag registry are translated; nothing else from the
// application is needed, so the setup variant avoids database access.
@I18N::init('en', true);
Registry::elementFactory(new ElementFactory());
// GEDCOM 5.5.1 plus webtrees' own tags. The custom-tag vendors need a database
// (they read site preferences), so they stay out of this isolated check.
(new Gedcom())->registerTags(Registry::elementFactory(), false);

$checks = 0;
function check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }

// The exact shape from the issue: a source record written as if it were a
// citation. webtrees renders these three lines as errors.
$citation_form = "1 TITL Geboorteakte, akte 84\n1 DATE 27 NOV 1828\n1 PLAC Bedum\n1 DATA\n2 TEXT volledige transcriptie";
$problems = GedcomStructure::problems('SOUR', $citation_form);
check(array_keys($problems) === ['SOUR:DATE', 'SOUR:PLAC', 'SOUR:DATA:TEXT'], 'Citation-only tags are rejected on a source record: ' . json_encode(array_keys($problems)));
check($problems['SOUR:DATE'] === [2] && $problems['SOUR:DATA:TEXT'] === [5], 'Problems point at the offending lines');

// The structure webtrees does accept for the same information.
$source_form = "1 TITL Geboorteakte, akte 84\n1 DATA\n2 EVEN BIRT\n3 DATE 27 NOV 1828\n3 PLAC Bedum\n1 TEXT volledige transcriptie";
check(GedcomStructure::problems('SOUR', $source_form) === [], 'DATA > EVEN > DATE/PLAC and TEXT at level 1 are accepted');

// Ordinary records stay valid.
check(GedcomStructure::problems('INDI', "1 NAME Jan\n1 BIRT\n2 DATE 1 JAN 1900\n2 PLAC Bedum\n1 SOUR @S1@\n2 PAGE 12") === [], 'A normal individual fragment passes');
check(GedcomStructure::problems('FAM', "1 HUSB @I1@\n1 WIFE @I2@\n1 CHIL @I3@\n1 MARR\n2 DATE 1 JAN 1900") === [], 'A normal family fragment passes');
check(GedcomStructure::problems('SOUR', "0 @X1@ SOUR\n1 TITL Titel") === [], 'A level 0 line does not disturb the paths');

// Unknown tags are still unknown one level deeper.
$nested = GedcomStructure::problems('INDI', "1 BIRT\n2 DATE 1 JAN 1900\n2 XYZ Bedum");
check(array_keys($nested) === ['INDI:BIRT:XYZ'], 'Nested unknown tags are named by their full path');

// Unknown structures the record already carries must not block editing it,
// otherwise the tags of issue #20 could never be removed.
$tagged = "0 @S1@ SOUR\n1 TITL Oud\n1 _WT_API_CREATE key hash id";
check(GedcomStructure::problems('SOUR', $tagged, $tagged) === [], 'A legacy tag already on the record is tolerated');
check(GedcomStructure::problems('SOUR', $tagged . "\n1 _WT_API_CREATE key hash id2") !== [], 'A second unknown occurrence is still reported');

// The message has to say what to do next.
$message = GedcomStructure::message($problems);
check(str_contains($message, 'SOUR:DATE') && str_contains($message, 'SOUR:DATA:TEXT'), 'Message names every offending path');
check(str_contains($message, 'Allowed under SOUR:') && str_contains($message, 'TITL'), 'Message lists the tags webtrees accepts there');

// Fail open when the registry is not available: never reject a whole record
// because the environment could not load the tags.
check(GedcomStructure::problems('', "1 DATE x") === [], 'No record type means no validation');
check(GedcomStructure::problems('_LOC', "1 FOO x") === [], 'A record type the registry does not know is left to webtrees');

// webtrees registers some tags as sub-fields of the parent element rather than
// as elements of their own (that is how _LOC, _GOV and _POST are accepted under
// PLAC). Those must not be reported as unknown.
Registry::elementFactory()->registerSubTags(['INDI:BIRT' => [['_STATE', '0:1']]]);
check(GedcomStructure::problems('INDI', "1 BIRT\n2 _STATE Bedum") === [], 'A sub-field the parent element declares is accepted');
check(GedcomStructure::problems('INDI', "1 BIRT\n2 _OTHER Bedum") !== [], 'An undeclared sub-field is still reported');

echo "PASS: {$checks} GEDCOM structure checks against webtrees 2.2 tags.\n";
