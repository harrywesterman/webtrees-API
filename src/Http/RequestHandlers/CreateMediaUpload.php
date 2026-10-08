<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use DomainException;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaInput;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaToken;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
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
            $access = CheckAccess::checkUserWriteAccess($tree);
            if ($access->getStatusCode() !== 200) return $access;
            if (array_key_exists('files', $input)) {
                $files = $input['files'];
                if (!is_array($files) || !array_is_list($files) || count($files) < 1 || count($files) > 50) {
                    throw new DomainException('files must contain between 1 and 50 objects.', 400);
                }
                $prepared = [];
                foreach ($files as $file) {
                    if (!is_array($file)) throw new DomainException('Each file must be an object.', 400);
                    // Per-file metadata and target; the tree is always inherited.
                    $params = array_replace($input, $file, ['tree' => $tree->name()]);
                    unset($params['files']);
                    $this->media->validateUploadTarget($tree, $params);
                    MediaInput::filename(MediaInput::text($params, 'filename'));
                    $prepared[] = $params;
                }
                $uploads = [];
                foreach ($prepared as $params) {
                    $response = $this->handle($request->withQueryParams($params));
                    if ($response->getStatusCode() !== 201) return $response;
                    $uploads[] = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR) + [
                        'filename' => $params['filename'], 'target-xref' => $params['target-xref'], 'target-type' => $params['target-type'],
                    ];
                }
                return api_response(['files' => $uploads, 'staged' => true, 'message' => 'PUT each local file to its upload-url. Each PUT returns record hashes. URLs are single-use; individual PUTs commit independently.'], 201);
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
                'message' => 'PUT the raw image bytes to upload-url with Content-Type image/jpeg, image/png, image/gif, image/webp or application/pdf. Do not place bytes or base64 in the MCP request.',
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
            'description' => 'Create signed URLs for raw image/PDF uploads, then PUT each local file with a client file/HTTP tool. Supply a single filename/target or files (1-50 objects), each with filename and optional per-file target-xref, target-type, title, note and date. Omit top-level target fields when every file supplies its own target. URLs commit independently and can queue on the same target. Keeps base64 out of the MCP request and works for files up to 20 MiB. Verify the tree and target first. The media record and target link await moderator approval; do not repeat the upload.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'tree' => ['type' => 'string', 'description' => 'Exact tree name from get-trees.'],
                'target-xref' => ['type' => 'string', 'description' => 'Existing target XREF; verify using get-record before writing.'],
                'target-type' => ['type' => 'string', 'enum' => ['INDI', 'FAM', 'SOUR']],
                'filename' => ['type' => 'string', 'maxLength' => 180, 'description' => 'Image/PDF basename, never a server path.'],
                'files' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['type' => 'object', 'properties' => [
                    'filename' => ['type' => 'string'], 'target-xref' => ['type' => 'string'], 'target-type' => ['type' => 'string', 'enum' => ['INDI', 'FAM', 'SOUR']],
                    'title' => ['type' => 'string'], 'note' => ['type' => 'string'], 'date' => ['type' => 'string'],
                ], 'required' => ['filename'], 'additionalProperties' => false]],
                'title' => ['type' => 'string', 'maxLength' => 16384],
                'note' => ['type' => 'string', 'maxLength' => 16384],
                'date' => ['type' => 'string', 'maxLength' => 16384],
            ], 'required' => ['tree'], 'anyOf' => [['required' => ['files']], ['required' => ['target-xref', 'target-type', 'filename']]], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object', 'properties' => [
                'files' => ['type' => 'array', 'items' => ['type' => 'object']],
                'staged' => ['type' => 'boolean'],
                'upload-id' => ['type' => 'string'],
                'upload-url' => ['type' => 'string'],
                'max-bytes' => ['type' => 'integer'],
                'expires-at' => ['type' => 'integer'],
            ], 'anyOf' => [['required' => ['files', 'staged']], ['required' => ['upload-id', 'upload-url', 'max-bytes', 'expires-at']]]],
            'annotations' => ['title' => 'create-media-upload', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
        ];
    }
}
