<?php

/** Pure capability-token tests: signing, tamper resistance, expiry and purpose binding. */
declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaToken;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}

$key = MediaToken::key('a-test-encryption-key');
check($key === MediaToken::key('a-test-encryption-key'), 'Key derivation is stable');
check($key !== MediaToken::key('another-key'), 'Different encryption keys derive different signing keys');

$token = MediaToken::sign(['op' => 'content', 'tree' => 'test', 'xref' => 'M1', 'file' => 'api-media/ab/scan.png'], $key);
$claims = MediaToken::verify($token, $key, 'content');
check($claims['tree'] === 'test' && $claims['xref'] === 'M1' && $claims['file'] === 'api-media/ab/scan.png', 'Claims survive round trip');
check($claims['exp'] >= time() && $claims['exp'] <= time() + MediaToken::TTL, 'Expiry is bounded');

try {
    MediaToken::verify($token, $key, 'preview');
    throw new RuntimeException('Purpose confusion must be rejected');
} catch (DomainException $e) {
    check($e->getCode() === 403, 'Purpose binding');
}

try {
    MediaToken::verify($token, MediaToken::key('wrong'), 'content');
    throw new RuntimeException('Wrong key must be rejected');
} catch (DomainException $e) {
    check($e->getCode() === 403, 'Signature check');
}

$tampered = explode('.', $token);
$tampered[0] = rtrim(strtr(base64_encode('{"op":"content","tree":"evil"}'), '+/', '-_'), '=');
try {
    MediaToken::verify(implode('.', $tampered), $key, 'content');
    throw new RuntimeException('Tampered payload must be rejected');
} catch (DomainException $e) {
    check($e->getCode() === 403, 'Tamper resistance');
}

$expired = MediaToken::sign(['op' => 'content', 'tree' => 'test'], $key, -1);
try {
    MediaToken::verify($expired, $key, 'content');
    throw new RuntimeException('Expired token must be rejected');
} catch (DomainException $e) {
    check($e->getCode() === 403, 'Expiry enforced');
}

foreach (['', 'not-a-token', 'a.b.c', '.', 'abc.'] as $bad) {
    try {
        MediaToken::verify($bad, $key, 'content');
        throw new RuntimeException('Malformed token must be rejected: ' . $bad);
    } catch (DomainException $e) {
        check($e->getCode() === 403, 'Malformed token rejected');
    }
}

try {
    MediaToken::sign(['op' => 'content'], '');
    throw new RuntimeException('Empty key must be refused');
} catch (DomainException $e) {
    check($e->getCode() === 500, 'Empty key refused');
}

echo "PASS: {$checks} media capability-token checks.\n";
