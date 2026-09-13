<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\MediaTools;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DeleteMedia implements WebtreesMcpToolRequestHandlerInterface
{
    public function __construct(private Media $media) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->media->execute($request->withAttribute('media_mcp', true), 'delete-media');
    }

    public static function getMcpToolDescription(): array
    {
        return MediaTools::description('delete-media');
    }
}

