<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$checks = 0;
function check(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }

$lock = "DB::table('gedcom')->where('gedcom_id', \$tree->id())->lockForUpdate()->first()";
foreach (['GetSources.php', 'SearchStructured.php', 'AddFamily.php', 'CreateRecords.php'] as $file) {
    $source = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/' . $file);
    // Locking the tree row to serialize writers is fine; reading records from
    // wt_gedcom is not (it holds the tree, not the GEDCOM).
    check(!str_contains(str_replace($lock, '', $source), "DB::table('gedcom')"), $file . ' does not query wt_gedcom as records');
}
$search = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/SearchStructured.php');
$sources = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/GetSources.php');
check(str_contains($search, "['individuals', 'i_file'"), 'Search reads individuals');
check(str_contains($search, "['families', 'f_file'"), 'Search reads families');
check(str_contains($search, "['sources', 's_file'"), 'Search reads sources');
check(str_contains($sources, "DB::table('sources')"), 'Source listing reads sources');
echo "Webtrees schema contract checks passed: {$checks}\n";
