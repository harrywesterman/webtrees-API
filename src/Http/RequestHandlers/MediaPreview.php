<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use DomainException;
use Fisharebest\Webtrees\Media as MediaRecord;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaInput;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaToken;
use Nyholm\Psr7\Response;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

/**
 * Serve a small JPEG preview of a visible media file authorized by a signed token.
 * Lets a client show an image without downloading full-resolution bytes.
 */
final class MediaPreview implements RequestHandlerInterface
{
    private const int MAX_EDGE = 480;
    private const int MAX_PIXELS = 40000000;

    public function __construct(private TreeService $trees) {}

    #[OA\Get(
        path: '/media/preview',
        description: 'Stream a bounded JPEG thumbnail of a visible media file authorized by a signed token from get-media.',
        tags: ['webtrees'],
        parameters: [new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'JPEG thumbnail'), new OA\Response(response: 403, description: 'Invalid or expired token'), new OA\Response(response: 415, description: 'No preview available for this file')],
    )]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $key = MediaToken::moduleKey();
            $claims = MediaToken::verify((string) ($request->getQueryParams()['token'] ?? ''), $key, 'preview');
            $tree = $this->trees->all()[(string) $claims['tree']] ?? null;
            if (!$tree instanceof Tree) {
                throw new DomainException('Media file not found.', 404);
            }
            $file = MediaInput::path((string) $claims['file']);
            $this->assertRecordFile($tree, (string) $claims['xref'], $file);
            $filesystem = $tree->mediaFilesystem();
            if (!$filesystem->fileExists($file)) {
                throw new DomainException('Media file not found.', 404);
            }
            $bytes = $filesystem->read($file);
            return new Response(200, [
                'Content-Type' => 'image/jpeg',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=300',
            ], $this->thumbnail($bytes));
        } catch (DomainException $e) {
            return api_response($e->getMessage(), $e->getCode() ?: 403);
        } catch (Throwable) {
            return api_response('Media preview unavailable.', 500);
        }
    }

    private function thumbnail(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new DomainException('No preview is available for this file.', 415);
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new DomainException('No preview is available for this file.', 415);
        }
        $scale = min(1.0, self::MAX_EDGE / max($info[0], $info[1]));
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $thumb = $scale < 1.0 ? imagescale($source, $width, $height) : $source;
        ob_start();
        imagejpeg($thumb, null, 82);
        return (string) ob_get_clean();
    }

    private function assertRecordFile(Tree $tree, string $xref, string $file): void
    {
        if (preg_match('/^[A-Za-z0-9_:-]{1,64}$/D', $xref) !== 1) {
            throw new DomainException('Media file not found.', 404);
        }
        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        if (!$record instanceof MediaRecord) {
            throw new DomainException('Media file not found.', 404);
        }
        if (preg_match('/\n1 FILE ' . preg_quote($file, '/') . '(?:\n|$)/', $record->gedcom()) !== 1) {
            throw new DomainException('Media file not found.', 404);
        }
    }
}
