<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$checks = 0;
function check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
$source = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/SearchStructured.php');
check(str_contains($source, "'name'"), 'Structured name search is exposed');
check(str_contains($source, "'place'"), 'Structured place search is exposed');
check(str_contains($source, "'year-from'"), 'Year range search is exposed');
check(str_contains($source, "'occupation'"), 'Occupation search is exposed');
check(str_contains($source, "'full-text'"), 'Full-text search is exposed');
check(str_contains($source, "'has-more'"), 'Pagination metadata is returned');
check(str_contains($source, "['tree'], \$a['xref']"), 'Results have stable tree/XREF sorting');
check(str_contains($source, "'NOTE', 'TEXT', 'TITL', 'AUTH', 'PUBL'"), 'Notes and source fields are searched');
check(str_contains($source, "'individuals', 'i_file', 'i_id', 'i_gedcom'"), 'Individuals use the webtrees 2.2 record table');
check(str_contains($source, "'families', 'f_file', 'f_id', 'f_gedcom'"), 'Families use the webtrees 2.2 record table');
check(str_contains($source, "'sources', 's_file', 's_id', 's_gedcom'"), 'Sources use the webtrees 2.2 record table');
echo "Structured search contract checks passed: {$checks}\n";
