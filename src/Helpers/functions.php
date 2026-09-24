<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2026 webtrees development team
 *                    <http://webtrees.net>
 *
 * CustomModuleManager (webtrees custom module):
 * Copyright (C) 2026 Markus Hemprich
 *                    <http://www.familienforschung-hemprich.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *
 * webtrees API
 *
 * A webtrees(https://webtrees.net) 2.2 custom module to provide an API for webtrees
 *
 */

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Enums\HttpStatusCode;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Webtrees;
use Psr\Http\Message\ResponseInterface;


/**
 * Create a response.
 *
 * @param array<mixed>|object|string $content
 * @param int                        $code
 * @param array<string>              $headers
 *
 * @return ResponseInterface
 */
function api_response(array|object|string $content = '', int $code = StatusCodeInterface::STATUS_OK, array $headers = []): ResponseInterface
{
    // Avoid that webtrees response will return 204 STATUS_NO_CONTENT in case of an empty content
    if ($content === '' && $code === StatusCodeInterface::STATUS_OK) {
        $content = 'OK';
    }

    // Keep failures machine-readable for API and MCP clients while retaining
    // the original human-readable message.
    if ($code >= 400 && is_string($content)) {
        $content = [
            'error' => [
                'code' => match (true) {
                    $code === StatusCodeInterface::STATUS_UNAUTHORIZED => 'token_invalid',
                    $code === StatusCodeInterface::STATUS_FORBIDDEN => 'scope_missing',
                    $code === StatusCodeInterface::STATUS_CONFLICT && str_contains(strtolower($content), 'protected') => 'protected_links_would_be_removed',
                    $code === StatusCodeInterface::STATUS_CONFLICT && str_contains(strtolower($content), 'uncertain') => 'upload_commit_uncertain',
                    $code === StatusCodeInterface::STATUS_CONFLICT && str_contains(strtolower($content), 'pending') => 'pending_conflict',
                    $code === StatusCodeInterface::STATUS_CONFLICT => 'conflict',
                    $code === StatusCodeInterface::STATUS_PAYLOAD_TOO_LARGE => 'inline_upload_too_large',
                    $code >= 500 => 'internal_error',
                    default => 'invalid_request',
                },
                'message' => $content,
            ],
        ];
    }

    // As a default, webtrees-API returns text/plain. Avoid that webtrees response will return text/HTML as content-type
    if (is_string($content)) {
        $headers['content-type'] ??= 'text/plain';
    }

    if (version_compare(Webtrees::VERSION, '2.3.0', '>=')) {
        return Registry::responseFactory()->response($content, HttpStatusCode::from($code), $headers);
    }
    else {
        return Registry::responseFactory()->response($content, $code, $headers);
    }
}
