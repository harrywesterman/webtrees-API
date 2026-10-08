<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\MediaTools;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class UploadMedia implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private Media $media, private CreateMediaUpload $staged) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!array_key_exists('content-base64', $request->getQueryParams())) return $this->staged->handle($request);
        if (($request->getQueryParams()['legacy-inline'] ?? false) !== true) return \Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response('Inline base64 requires legacy-inline=true; omit bytes to receive an upload-url.', 400);
        return $this->media->execute($request->withAttribute('media_mcp', true), 'upload-media');
    }

    public static function getMcpToolDescription(): array
    {
        return MediaTools::description('upload-media');
    }
}

