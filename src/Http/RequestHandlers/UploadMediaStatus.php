<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use DomainException;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaChunkStore;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class UploadMediaStatus implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private MediaChunkStore $store) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $identity = [];
            foreach (['oauth_client_id', 'oauth_user_id', 'oauth_access_token_id'] as $key) {
                $value = $request->getAttribute($key);
                if ((!is_string($value) && !is_int($value)) || (string) $value === '') throw new DomainException('Authenticated token identity required.', 403);
                $identity[] = (string) $value;
            }
            return $this->store->status(json_encode($identity, JSON_THROW_ON_ERROR), (string) ($request->getQueryParams()['upload-id'] ?? ''));
        } catch (DomainException $e) {
            return api_response($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable) {
            return api_response('Upload status lookup failed.', 500);
        }
    }

    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_UPLOAD_MEDIA_STATUS, 'description' => 'Read the status of your resumable media upload. Use this after an uncertain transport or commit result; never start a new upload ID blindly.', 'inputSchema' => ['type' => 'object', 'properties' => ['upload-id' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{32}$']], 'required' => ['upload-id']], 'outputSchema' => ['type' => 'object'], 'annotations' => ['title' => WebtreesApi::PATH_UPLOAD_MEDIA_STATUS, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]];
    }
}
