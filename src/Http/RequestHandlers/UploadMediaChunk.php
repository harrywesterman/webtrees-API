<?php
declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use DomainException;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\MediaTools;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaChunkStore;
use Jefferson49\Webtrees\Module\WebtreesApi\OAuth2\Repositories\ScopeRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class UploadMediaChunk implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private Media $media, private MediaChunkStore $store) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (!in_array(ScopeRepository::SCOPE_MCP_WRITE, $request->getAttribute('oauth_scopes', []), true)) {
                throw new DomainException('mcp_write is required.', 403);
            }
            $identity = [];
            foreach (['oauth_client_id', 'oauth_user_id', 'oauth_access_token_id'] as $key) {
                $value = $request->getAttribute($key);
                if ((!is_string($value) && !is_int($value)) || (string) $value === '') {
                    throw new DomainException('Authenticated token identity required.', 403);
                }
                $identity[] = (string) $value;
            }
            return $this->store->accept(json_encode($identity, JSON_THROW_ON_ERROR), $request->getQueryParams(),
                function ($file, $metadata) use ($request) {
                    return $this->media->execute($request->withQueryParams($metadata)
                        ->withAttribute('media_mcp', true)->withAttribute('media_chunk_file', $file), 'upload-media');
                })->withHeader('Cache-Control', 'private, no-store');
        } catch (DomainException $e) {
            return api_response($e->getMessage(), $e->getCode() ?: 400)->withHeader('Cache-Control', 'private, no-store');
        } catch (Throwable) {
            return api_response('Chunk upload failed. Retry only the same request and upload ID; an uncertain commit requires administrator verification.', 500)
                ->withHeader('Cache-Control', 'private, no-store');
        }
    }

    public static function getMcpToolDescription(): array
    {
        return MediaTools::description('upload-media-chunk');
    }
}
