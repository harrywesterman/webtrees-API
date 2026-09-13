<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\MediaTools;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class UnlinkMedia implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private Media $media) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->media->execute($request->withAttribute('media_mcp', true), 'unlink-media');
    }

    public static function getMcpToolDescription(): array
    {
        return MediaTools::description('unlink-media');
    }
}

