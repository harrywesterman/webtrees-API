<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MediaDownload implements RequestHandlerInterface
{
    public function __construct(private Media $media) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $request->getAttribute('media_http_method', $request->getMethod()) === 'GET' ? $this->media->execute($request, 'download-media') : new \Nyholm\Psr7\Response(405);
    }
}
