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
    public const string METHOD_DESCRIPTION = 'Download a visible media file for the local MCP bridge. The bridge writes the bytes to a temporary local file.';

    public function __construct(private Media $media) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->media->execute($request->withAttribute('media_mcp', true), 'download-media');
            if ($response->getStatusCode() !== StatusCodeInterface::STATUS_OK) return $response;
            $bytes = (string) $response->getBody();
            return api_response([
                'filename' => basename($request->getQueryParams()['filename'] ?? 'download.bin'),
                'bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'content-base64' => base64_encode($bytes),
            ], StatusCodeInterface::STATUS_OK);
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
            'outputSchema' => ['type' => 'object', 'properties' => ['filename' => ['type' => 'string'], 'bytes' => ['type' => 'integer'], 'sha256' => ['type' => 'string'], 'content-base64' => ['type' => 'string']], 'required' => ['filename', 'bytes', 'sha256', 'content-base64']],
            'annotations' => ['title' => WebtreesApi::PATH_DOWNLOAD_MEDIA, 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ];
    }
}
