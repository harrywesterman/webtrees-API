<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MediaLinks implements RequestHandlerInterface
{
    public function __construct(private Media $media) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->media->execute($request, match ($request->getMethod()) { 'POST' => 'link-media', 'DELETE' => 'unlink-media', default => '' });
    }
}

