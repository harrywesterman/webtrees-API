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
 * Stream a visible media file authorized by a short-lived signed capability token.
 * Registered without OAuth so a client can open the URL directly; the token was
 * minted only after the caller passed the normal scope, record and privacy checks.
 */
final class MediaContent implements RequestHandlerInterface
{
    public function __construct(private TreeService $trees) {}

    #[OA\Get(
        path: '/media/content',
        description: 'Stream a visible media file authorized by a short-lived signed token from get-media. No bearer token is required.',
        tags: ['webtrees'],
        parameters: [new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'File bytes'), new OA\Response(response: 403, description: 'Invalid or expired token'), new OA\Response(response: 404, description: 'File not found')],
    )]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $key = MediaToken::moduleKey();
            $claims = MediaToken::verify((string) ($request->getQueryParams()['token'] ?? ''), $key, 'content');
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
            $mime = MediaInput::mimeForName($file);
            $disposition = MediaInput::isDocument($mime) ? 'attachment' : 'inline';
            return new Response(200, [
                'Content-Type' => $mime,
                'Content-Length' => (string) $filesystem->fileSize($file),
                'Content-Disposition' => $disposition . '; filename="' . basename($file) . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=300',
            ], $filesystem->readStream($file));
        } catch (DomainException $e) {
            return api_response($e->getMessage(), $e->getCode() ?: 403);
        } catch (Throwable) {
            return api_response('Media content unavailable.', 500);
        }
    }

    /** Confirm the token's file is really a FILE fact of the named media record. */
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
