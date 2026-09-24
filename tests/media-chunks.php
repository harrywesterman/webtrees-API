<?php
declare(strict_types=1);

require getenv('WEBTREES_TEST_ROOT') . '/vendor/autoload.php';
require __DIR__ . '/../autoload.php';

use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaChunkStore;
use Nyholm\Psr7\Response;

function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function rejects(callable $call, int $code): void {
    try { $call(); } catch (DomainException $e) {
        check($e->getCode() === $code, $e->getMessage()); return;
    }
    throw new RuntimeException('Expected rejection ' . $code);
}
function uploadId(?int $timestamp = null): string { return sprintf('%08x', $timestamp ?? time()) . bin2hex(random_bytes(12)); }
check(class_exists(MediaChunkStore::class), 'Chunk upload store must exist');
$dir = sys_get_temp_dir() . '/media-chunks-test-' . bin2hex(random_bytes(8));
$store = new MediaChunkStore($dir);
$bytes = 'abcdef';
$input = ['upload-id' => uploadId(), 'offset' => 0, 'content-base64' => base64_encode('abc'),
    'total-bytes' => 6, 'sha256' => hash('sha256', $bytes), 'filename' => 'scan.png',
    'tree' => 'test', 'target-xref' => 'I1', 'target-type' => 'INDI', 'final' => false];
$writes = 0;
$commit = function ($file, $metadata) use (&$writes, $bytes) {
    ++$writes;
    check((string) $file->getStream() === $bytes, 'Exact assembled bytes');
    check($metadata['tree'] === 'test', 'Metadata retained');
    return new Response(201, ['Content-Type' => 'application/json'], '{"xref":"M1","pending":true}');
};
try {
    $first = $store->accept('owner', $input, $commit);
    check(json_decode((string) $first->getBody(), true)['next-offset'] === 3, 'Acknowledged offset');
    check(json_decode((string) $store->status('owner', $input['upload-id'])->getBody(), true)['state'] === 'receiving', 'Upload status reports receiving');
    check((string) $store->accept('owner', $input, $commit)->getBody() === (string) $first->getBody(), 'Chunk replay');
    rejects(fn () => $store->accept('other', $input, $commit), 403);
    rejects(fn () => $store->accept('owner', array_replace($input, ['offset' => 4, 'content-base64' => 'ZQ==']), $commit), 409);
    rejects(fn () => $store->accept('owner', array_replace($input, ['title' => 'changed']), $commit), 409);
    rejects(fn () => $store->accept('owner', array_replace($input, ['content-base64' => 'eHl6']), $commit), 409);
    $last = array_replace($input, ['offset' => 3, 'content-base64' => base64_encode('def'), 'final' => true]);
    $result = $store->accept('owner', $last, $commit);
    check($result->getStatusCode() === 201 && $writes === 1, 'Commit once');
    check((string) $store->accept('owner', $last, $commit)->getBody() === (string) $result->getBody() && $writes === 1, 'Final replay never commits twice');
    $done = json_decode((string) $store->status('owner', $input['upload-id'])->getBody(), true);
    check($done['state'] === 'done' && $done['response']['xref'] === 'M1', 'Upload status reports durable receipt');
    foreach ([['offset' => '0'], ['final' => 'false'], ['upload-id' => '../escape'], ['sha256' => 'bad'], ['content-base64' => "YQ==\n"]] as $bad) {
        rejects(fn () => $store->accept('owner', array_replace($input, $bad), $commit), 400);
    }
    rejects(fn () => $store->accept('owner', array_replace($input, ['total-bytes' => 20971521]), $commit), 413);
    rejects(fn () => $store->accept('owner', array_replace($input, ['content-base64' => base64_encode(str_repeat('a', 262145))]), $commit), 413);
    $single = array_replace($input, ['upload-id' => uploadId(), 'content-base64' => base64_encode($bytes), 'final' => true]);
    rejects(fn () => $store->accept('owner', array_replace($single, ['sha256' => str_repeat('0', 64)]), $commit), 400);
    check($writes === 1, 'Bad digest never commits');
    $single['upload-id'] = uploadId();
    $uncertain = function () { throw new RuntimeException('Commit outcome unknown'); };
    try { $store->accept('owner', $single, $uncertain); } catch (RuntimeException) {}
    rejects(fn () => $store->accept('owner', $single, $commit), 409);
    check($writes === 1, 'Ambiguous commit never retries');
    $failed = array_replace($single, ['upload-id' => uploadId()]);
    $store->accept('owner', $failed, fn () => new Response(500));
    rejects(fn () => $store->accept('owner', $failed, $commit), 409);
    rejects(fn () => $store->accept('owner', array_replace($input, ['upload-id' => uploadId(time() - 3600)]), $commit), 410);
    rejects(fn () => $store->accept('owner', array_replace($input, ['upload-id' => uploadId(time() + 600)]), $commit), 400);
    rejects(fn () => $store->accept('owner', array_replace($input, ['upload-id' => uploadId(), 'offset' => 1]), $commit), 409);
    $expires = array_replace($input, ['upload-id' => uploadId(time() - 3599)]);
    $store->accept('owner', $expires, $commit);
    sleep(2);
    rejects(fn () => $store->accept('owner', $expires, $commit), 410);
    check(!file_exists($dir . '/' . $expires['upload-id'] . '.json') && !file_exists($dir . '/' . $expires['upload-id'] . '.data'), 'Expired state and bytes removed');
    // Full declared sizes are reserved before accepting data, not just current chunk sizes.
    $large = array_replace($input, ['upload-id' => uploadId(), 'total-bytes' => 20971520]);
    $store->accept('quota-owner', $large, $commit);
    $large['upload-id'] = uploadId();
    $store->accept('quota-owner', $large, $commit);
    $large['upload-id'] = uploadId();
    rejects(fn () => $store->accept('quota-owner', $large, $commit), 429);
    // Crash after append but before offset persistence: truncate the unacknowledged suffix.
    $recover = array_replace($input, ['upload-id' => uploadId()]);
    $store->accept('owner', $recover, $commit);
    file_put_contents($dir . '/' . $recover['upload-id'] . '.data', 'unacknowledged', FILE_APPEND);
    $recover = array_replace($recover, ['offset' => 3, 'content-base64' => base64_encode('def'), 'final' => true]);
    check($store->accept('owner', $recover, $commit)->getStatusCode() === 201, 'Recover interrupted append');
    if (function_exists('pcntl_fork')) {
        // Both workers submit the same final request. A filesystem marker detects duplicate commits.
        $parallel = array_replace($single, ['upload-id' => uploadId()]);
        $parallelCommit = function () use ($dir) {
            $marker = fopen($dir . '/commit-once', 'x');
            if ($marker === false) { throw new RuntimeException('Duplicate commit'); }
            fclose($marker);
            usleep(100000);
            return new Response(201, [], '{"xref":"M2","pending":true}');
        };
        $pid = pcntl_fork();
        if ($pid === -1) { throw new RuntimeException('fork failed'); }
        if ($pid === 0) {
            $response = (new MediaChunkStore($dir))->accept('parallel', $parallel, $parallelCommit);
            exit($response->getStatusCode() === 201 ? 0 : 1);
        }
        $response = $store->accept('parallel', $parallel, $parallelCommit);
        pcntl_waitpid($pid, $status);
        check($response->getStatusCode() === 201 && pcntl_wexitstatus($status) === 0, 'Concurrent final replay serializes and commits once');
    }
    echo "PASS: chunk assembly, isolation, ordering, validation, digest, replay, expiry, quotas and locking.\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($dir)) { rmdir($dir); }
}
