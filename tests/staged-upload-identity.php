<?php

declare(strict_types=1);

/** Contract checks for the identity binding of the signed PUT upload route. */
$source = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/CreateMediaUpload.php');
$upload = file_get_contents(__DIR__ . '/../src/Http/RequestHandlers/MediaUpload.php');

if (!is_string($source) || !is_string($upload)) {
    throw new RuntimeException('Cannot read staged-upload handlers.');
}

$checks = [
    [str_contains($source, "'user-id' => \$userId"), 'create-media-upload must bind the OAuth user id'],
    [str_contains($upload, 'loginFromToken($claims[\'user-id\'] ?? null)'), 'PUT route must restore the token identity'],
    [str_contains($upload, 'Auth::logout();'), 'PUT route must always log out the restored identity'],
    [str_contains($upload, 'Registry::container()->set(UserInterface::class, $user);'), 'restored identity must be available through the webtrees container'],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        throw new RuntimeException($message);
    }
}

echo "PASS: " . count($checks) . " staged-upload identity checks.\n";
