<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use DomainException;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaInput;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaToken;
use OpenApi\Attributes as OA;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

/**
 * Receive raw image bytes for a signed staged upload. Registered without OAuth:
 * the signed, single-use, short-lived token authorizes exactly one upload.
 */
final class MediaUpload implements RequestHandlerInterface
{
    public function __construct(private Media $media, private TreeService $trees, private string $spool = '') {}

    #[OA\Put(
        path: '/media/upload',
        description: 'Receive raw image or PDF bytes for a single-use signed staged upload created by create-media-upload. No bearer token is required.',
        tags: ['webtrees'],
        parameters: [new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
        responses: [new OA\Response(response: 201, description: 'Upload stored; pending approval'), new OA\Response(response: 403, description: 'Invalid or expired token'), new OA\Response(response: 409, description: 'Upload URL already used'), new OA\Response(response: 413, description: 'Upload too large')],
    )]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $key = MediaToken::moduleKey();
            $claims = MediaToken::verify((string) ($request->getQueryParams()['token'] ?? ''), $key, 'upload');
            $id = (string) ($claims['id'] ?? '');
            if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                throw new DomainException('Invalid upload token.', 403);
            }
            $tree = $this->trees->all()[(string) $claims['tree']] ?? null;
            if (!$tree instanceof Tree) {
                throw new DomainException('Tree not found.', 404);
            }
            $this->loginFromToken($claims['user-id'] ?? null);
            try {
                $name = MediaInput::filename((string) $claims['file']);
                $bytes = $this->readBody($request);
                $this->claimOnce($id);
                $input = [
                    'tree' => (string) $claims['tree'],
                    'target-xref' => (string) $claims['xref'],
                    'target-type' => (string) $claims['type'],
                    'filename' => $name,
                    'orig' => (string) ($claims['orig'] ?? ''),
                    'title' => (string) ($claims['title'] ?? ''),
                    'note' => (string) ($claims['note'] ?? ''),
                    'date' => (string) ($claims['date'] ?? ''),
                ];
                return $this->media->commitUpload($tree, $input, $bytes, $name, MediaInput::REST_LIMIT);
            } finally {
                Auth::logout();
            }
        } catch (DomainException $e) {
            return api_response($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable) {
            return api_response('Staged upload failed. Verify the record before retrying.', 500);
        }
    }

    /** Restore the identity that passed create-media-upload's access checks. */
    private function loginFromToken(mixed $value): void
    {
        $userId = is_int($value) || is_string($value) ? (int) $value : 0;
        if ($userId <= 0) {
            throw new DomainException('Upload token has no valid webtrees identity.', 403);
        }
        $userService = version_compare(Webtrees::VERSION, '2.3', '>=')
            ? new UserService(Registry::container()->get(ClockInterface::class))
            : new UserService();
        $user = $userService->find($userId);
        if (!$user instanceof UserInterface) {
            throw new DomainException('Upload token refers to an unknown webtrees user.', 403);
        }
        Auth::login($user);
        Session::put('language', $user->getPreference(UserInterface::PREF_LANGUAGE));
        Registry::container()->set(UserInterface::class, $user);
    }

    /** Read at most REST_LIMIT bytes, whether or not the client declared a length. */
    private function readBody(ServerRequestInterface $request): string
    {
        $length = $request->getHeaderLine('Content-Length');
        if (ctype_digit($length) && (float) $length > MediaInput::REST_LIMIT) {
            throw new DomainException('Upload exceeds the 20 MiB limit.', 413);
        }
        $stream = $request->getBody();
        $bytes = '';
        while (!$stream->eof() && strlen($bytes) <= MediaInput::REST_LIMIT) {
            $chunk = $stream->read(min(65536, MediaInput::REST_LIMIT + 1 - strlen($bytes)));
            if ($chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }
        if ($bytes === '' || strlen($bytes) > MediaInput::REST_LIMIT) {
            throw new DomainException('Empty upload or upload size limit exceeded.', 413);
        }
        return $bytes;
    }

    /** Refuse a second use of the same signed upload URL. */
    private function claimOnce(string $id): void
    {
        $directory = $this->spool !== '' ? $this->spool : sys_get_temp_dir() . '/webtrees-media-upload-' . substr(hash('sha256', __DIR__), 0, 16);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create upload storage.');
        }
        $handle = @fopen($directory . '/' . $id, 'xb');
        if ($handle === false) {
            throw new DomainException('This upload URL was already used.', 409);
        }
        fclose($handle);
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (filemtime($file) < time() - MediaToken::TTL) {
                @unlink($file);
            }
        }
    }
}
