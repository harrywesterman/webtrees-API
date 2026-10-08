<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\Mcp as McpSchema;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class UploadMediaBatch implements WebtreesMcpToolRequestHandlerInterface
{
    public const string METHOD_DESCRIPTION = 'Primary transport: request signed URLs for 1-50 files, then PUT raw bytes using client file/HTTP tools; per-file targets are supported and edits queue without waiting for approval. Set legacy-inline=true only for the compatibility base64 batch (one target, one pending change).';

    public function __construct(private Media $media, private CreateMediaUpload $staged) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (($request->getQueryParams()['legacy-inline'] ?? false) !== true) return $this->staged->handle($request);
        return $this->media->executeBatch($request->withAttribute('media_mcp', true));
    }

    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_UPLOAD_MEDIA_BATCH, 'description' => self::METHOD_DESCRIPTION,
            'inputSchema' => ['type' => 'object', 'properties' => [
                'legacy-inline' => ['type' => 'boolean', 'default' => false],
                'tree' => McpSchema::TREE, 'target-xref' => McpSchema::XREF,
                'target-type' => ['type' => 'string', 'enum' => ['INDI', 'FAM', 'SOUR']],
                'files' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['type' => 'object', 'properties' => [
                    'target-xref' => McpSchema::XREF, 'target-type' => ['type' => 'string', 'enum' => ['INDI', 'FAM', 'SOUR']],
                    'filename' => ['type' => 'string'], 'content-base64' => ['type' => 'string'], 'title' => ['type' => 'string'], 'note' => ['type' => 'string'], 'date' => ['type' => 'string'],
                ], 'required' => ['filename'], 'additionalProperties' => false]],
            ], 'required' => ['tree', 'files'], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object', 'properties' => ['files' => ['type' => 'array'], 'target-xref' => McpSchema::XREF, 'pending' => ['type' => 'boolean']], 'required' => ['files']],
            'annotations' => ['title' => WebtreesApi::PATH_UPLOAD_MEDIA_BATCH, 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]];
    }
}
