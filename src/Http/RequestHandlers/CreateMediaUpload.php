<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use DomainException;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaInput;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaToken;
use Jefferson49\Webtrees\Module\WebtreesApi\OAuth2\Repositories\ScopeRepository as Scopes;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

/**
 * Mint a short-lived signed URL so a client can stream raw image bytes to the
 * server without putting base64 (or any binary) into the MCP request.
 */
final class CreateMediaUpload implements WebtreesMcpToolRequestHandlerInterface
{
    public const int TTL = 600;

    public function __construct(private Media $media, private TreeService $trees) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $input = $request->getQueryParams();
            $scopes = $request->getAttribute('oauth_scopes', []);
            if (!array_intersect([Scopes::SCOPE_MCP_WRITE], $scopes)) {
                throw new DomainException('Insufficient media permissions.', 403);
            }
            $tree = $this->trees->all()[MediaInput::text($input, 'tree')] ?? null;
            if (!$tree instanceof Tree) {
                throw new DomainException('Tree not found.', 404);
            }
            $userId = (int) $request->getAttribute('oauth_user_id', 0);
            if ($userId <= 0 || !Auth::user()) {
                throw new DomainException('Staged uploads require an authenticated webtrees user.', 403);
            }
            $this->media->validateUploadTarget($tree, $input);
            $key = MediaToken::moduleKey();
            $base = rtrim((string) $request->getAttribute('base_url', ''), '/');
            if ($key === '' || $base === '') {
                throw new DomainException('Staged uploads are not configured on this server.', 503);
            }
            $claims = [
                'op' => 'upload',
                'tree' => $tree->name(),
                'xref' => MediaInput::text($input, 'target-xref'),
                'type' => MediaInput::text($input, 'target-type'),
                'file' => MediaInput::filename(MediaInput::text($input, 'filename')),
                'orig' => MediaInput::text($input, 'filename'),
                'title' => MediaInput::text($input, 'title'),
                'note' => MediaInput::text($input, 'note'),
                'date' => MediaInput::text($input, 'date'),
                // The PUT route deliberately has no OAuth middleware. Bind its
                // capability to the user who passed the normal access checks.
                'user-id' => $userId,
                'id' => bin2hex(random_bytes(16)),
            ];
            $token = MediaToken::sign($claims, $key, self::TTL);
            return api_response([
                'upload-id' => $claims['id'],
                'upload-url' => $base . '/api/' . WebtreesApi::PATH_MEDIA_UPLOAD . '?token=' . rawurlencode($token),
                'max-bytes' => MediaInput::REST_LIMIT,
                'expires-at' => time() + self::TTL,
                'message' => 'PUT the raw image bytes to upload-url with Content-Type image/jpeg, image/png, image/gif or image/webp. Do not place bytes or base64 in the MCP request.',
            ], 201);
        } catch (DomainException $e) {
            return api_response($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable) {
            return api_response('Staged upload could not be created. Verify the record before retrying.', 500);
        }
    }

    public static function getMcpToolDescription(): array
    {
        return [
            'name' => 'create-media-upload',
            'description' => 'Create a short-lived signed URL for a raw image upload, then PUT the bytes there. Keeps base64 out of the MCP request and works for files up to 20 MiB. Verify the tree and target first. The media record and target link await moderator approval; do not repeat the upload.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'tree' => ['type' => 'string', 'description' => 'Exact tree name from get-trees.'],
                'target-xref' => ['type' => 'string', 'description' => 'Existing target XREF; verify using get-record before writing.'],
                'target-type' => ['type' => 'string', 'enum' => ['INDI', 'FAM', 'SOUR']],
                'filename' => ['type' => 'string', 'maxLength' => 180, 'description' => 'Basename including JPEG/PNG/GIF/WebP extension, never a path.'],
                'title' => ['type' => 'string', 'maxLength' => 16384],
                'note' => ['type' => 'string', 'maxLength' => 16384],
                'date' => ['type' => 'string', 'maxLength' => 16384],
            ], 'required' => ['tree', 'target-xref', 'target-type', 'filename'], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object', 'properties' => [
                'upload-id' => ['type' => 'string'],
                'upload-url' => ['type' => 'string'],
                'max-bytes' => ['type' => 'integer'],
                'expires-at' => ['type' => 'integer'],
            ], 'required' => ['upload-id', 'upload-url', 'max-bytes', 'expires-at']],
            'annotations' => ['title' => 'create-media-upload', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
        ];
    }
}
