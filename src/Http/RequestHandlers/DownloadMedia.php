<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\Mcp as McpSchema;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

final class DownloadMedia implements WebtreesMcpToolRequestHandlerInterface
{
    public const string METHOD_DESCRIPTION = 'Get a short-lived, single-file download URL for a visible media file. No bytes or base64 pass through the model; fetch the returned content-url with the bearer token. The local MCP bridge writes the fetched bytes to a temporary local file.';

    public function __construct(private Media $media) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->media->execute($request->withAttribute('media_mcp', true), 'download-media');
        } catch (Throwable $th) {
            return api_response($th->getMessage(), StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    public static function getMcpToolDescription(): array
    {
        return [
            'name' => WebtreesApi::PATH_DOWNLOAD_MEDIA,
            'description' => self::METHOD_DESCRIPTION,
            'inputSchema' => ['type' => 'object', 'properties' => [
                'tree' => McpSchema::TREE,
                'xref' => McpSchema::XREF,
                'filename' => ['type' => 'string', 'description' => 'Complete relative filename returned by get-media, including api-media/.../name.ext.'],
            ], 'required' => ['tree', 'xref', 'filename'], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object', 'properties' => [
                'filename' => ['type' => 'string'],
                'bytes' => ['type' => 'integer'],
                'sha256' => ['type' => 'string'],
                'content-url' => ['type' => 'string', 'description' => 'Short-lived signed URL. Fetch it with the bearer token; no bytes appear in this result.'],
                'expires-at' => ['type' => 'integer'],
                'content-base64' => ['type' => 'string', 'description' => 'Legacy inline fallback only, present when signed URLs are unavailable.'],
            ], 'required' => ['filename', 'bytes', 'sha256']],
            'annotations' => ['title' => WebtreesApi::PATH_DOWNLOAD_MEDIA, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ];
    }
}
