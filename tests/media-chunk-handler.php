<?php
/** Included by media.php to exercise the actual chunk handler and Media transaction. */
declare(strict_types=1);

use Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers\UploadMediaChunk;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaChunkStore;
use Nyholm\Psr7\ServerRequest;

$chunkDirectory = sys_get_temp_dir() . '/chunk-handler-test-' . bin2hex(random_bytes(8));
$chunks = new UploadMediaChunk($handler, new MediaChunkStore($chunkDirectory));
$chunkRequest = (new ServerRequest('GET', ''))->withAttribute('oauth_scopes', ['mcp_write'])
    ->withAttribute('oauth_client_id', 'client')->withAttribute('oauth_user_id', 'user')->withAttribute('oauth_access_token_id', 'token');
$imageBytes = $png . str_repeat("\0", 600000);
$chunkInput = ['upload-id' => sprintf('%08x', time()) . bin2hex(random_bytes(12)), 'offset' => 0,
    'content-base64' => base64_encode(substr($imageBytes, 0, 262144)), 'total-bytes' => strlen($imageBytes),
    'sha256' => hash('sha256', $imageBytes), 'filename' => 'scan.png', 'tree' => 'test', 'target-xref' => 'I1', 'target-type' => 'INDI',
    'title' => 'Chunked scan', 'note' => 'Verified note', 'date' => '1900', 'final' => false];
try {
    check($chunks->handle($chunkRequest->withAttribute('oauth_scopes', ['api_write'])->withQueryParams($chunkInput))->getStatusCode() === 403, 'api_write does not authorize MCP chunks');
    check($chunks->handle($chunkRequest->withoutAttribute('oauth_access_token_id')->withQueryParams($chunkInput))->getStatusCode() === 403, 'Missing token identity rejected');
    check(!file_exists($chunkDirectory), 'Unauthorized upload does not allocate storage');
    approved();
    foreach (str_split($imageBytes, 262144) as $index => $part) {
        $chunkInput['offset'] = $index * 262144;
        $chunkInput['content-base64'] = base64_encode($part);
        $chunkInput['final'] = $chunkInput['offset'] + strlen($part) === strlen($imageBytes);
        $response = $chunks->handle($chunkRequest->withQueryParams($chunkInput));
        check($response->getStatusCode() === ($chunkInput['final'] ? 201 : 200), 'Actual chunk upload: ' . $response->getBody());
    }
    $result = json_decode((string) $response->getBody(), true);
    check($result['pending'] === true && $fs->read($result['filename']) === $imageBytes, 'MCP-only >512KiB stores exact image and returns pending xref');
    check(\Fisharebest\Webtrees\DB::table('change')->count() === 2, 'Chunk finalize creates two pending changes');
    $gedcom = \Fisharebest\Webtrees\DB::table('change')->where('xref', $result['xref'])->value('new_gedcom');
    check(str_contains($gedcom, '2 TITL Chunked scan') && str_contains($gedcom, '1 NOTE Verified note') && str_contains($gedcom, '1 _DATE 1900'), 'Final retains all metadata');
    $replay = $chunks->handle($chunkRequest->withQueryParams($chunkInput));
    check((string) $replay->getBody() === (string) $response->getBody() && \Fisharebest\Webtrees\DB::table('change')->count() === 2, 'Actual final replay creates no records');
    foreach (['oauth_client_id', 'oauth_user_id', 'oauth_access_token_id'] as $attribute) {
        check($chunks->handle($chunkRequest->withAttribute($attribute, 'other')->withQueryParams($chunkInput))->getStatusCode() === 403, 'Identity binding ' . $attribute);
    }
    $spoof = $chunkInput + ['media_chunk_file' => 'fake'];
    check($chunks->handle($chunkRequest->withQueryParams($spoof))->getStatusCode() === 400, 'Arguments cannot inject trusted uploaded file');
    $oversizedFile = new \Nyholm\Psr7\UploadedFile(\Nyholm\Psr7\Stream::create(str_repeat('x', 20971521)), 20971521, UPLOAD_ERR_OK, 'large.png');
    check($handler->execute($chunkRequest->withAttribute('media_mcp', true)->withAttribute('media_chunk_file', $oversizedFile)->withQueryParams($chunkInput), 'upload-media')->getStatusCode() === 413, 'Internal file retains 20 MiB validation');
} finally {
    foreach (glob($chunkDirectory . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($chunkDirectory)) { rmdir($chunkDirectory); }
}
