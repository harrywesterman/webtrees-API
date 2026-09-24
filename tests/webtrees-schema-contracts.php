<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$checks = 0;
function check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }

foreach (['GetSources.php', 'SearchStructured.php', 'AddFamily.php'] as $file) {
    $source = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/' . $file);
    check(!str_contains($source, "DB::table('gedcom')"), $file . ' does not query wt_gedcom as records');
}
$search = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/SearchStructured.php');
$sources = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/GetSources.php');
check(str_contains($search, "['individuals', 'i_file'"), 'Search reads individuals');
check(str_contains($search, "['families', 'f_file'"), 'Search reads families');
check(str_contains($search, "['sources', 's_file'"), 'Search reads sources');
check(str_contains($sources, "DB::table('sources')"), 'Source listing reads sources');
echo "Webtrees schema contract checks passed: {$checks}\n";
