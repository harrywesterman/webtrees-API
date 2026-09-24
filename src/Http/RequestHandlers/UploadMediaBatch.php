<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\Mcp as McpSchema;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class UploadMediaBatch implements WebtreesMcpToolRequestHandlerInterface
{
    public const string METHOD_DESCRIPTION = 'Upload multiple local images and link them to one verified target in a single pending change.';

    public function __construct(private Media $media) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->media->executeBatch($request->withAttribute('media_mcp', true));
    }

    public static function getMcpToolDescription(): array
    {
        return ['name' => WebtreesApi::PATH_UPLOAD_MEDIA_BATCH, 'description' => self::METHOD_DESCRIPTION,
            'inputSchema' => ['type' => 'object', 'properties' => [
                'tree' => McpSchema::TREE, 'target-xref' => McpSchema::XREF,
                'target-type' => ['type' => 'string', 'enum' => ['INDI', 'FAM', 'SOUR']],
                'files' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['type' => 'object', 'properties' => [
                    'filename' => ['type' => 'string'], 'content-base64' => ['type' => 'string'], 'title' => ['type' => 'string'], 'note' => ['type' => 'string'], 'date' => ['type' => 'string'],
                ], 'required' => ['filename', 'content-base64'], 'additionalProperties' => false]],
            ], 'required' => ['tree', 'target-xref', 'target-type', 'files'], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object', 'properties' => ['files' => ['type' => 'array'], 'target-xref' => McpSchema::XREF, 'pending' => ['type' => 'boolean']], 'required' => ['files', 'target-xref', 'pending']],
            'annotations' => ['title' => WebtreesApi::PATH_UPLOAD_MEDIA_BATCH, 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]];
    }
}
