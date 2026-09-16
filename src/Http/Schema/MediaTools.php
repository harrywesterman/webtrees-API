<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema;

use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaInput;

final class MediaTools
{
    public const array ACTIONS = ['upload-media', 'get-media', 'update-media', 'link-media', 'unlink-media', 'delete-media', 'upload-media-chunk'];

    /** Shared source for the checked-in OpenAPI and runtime Swagger regeneration. */
    public static function openApiPaths(): array
    {
        $paths = [];
        foreach (['upload-media' => ['/media', 'post'], 'get-media' => ['/media', 'get'],
            'update-media' => ['/media', 'put'], 'delete-media' => ['/media', 'delete'],
            'link-media' => ['/media/links', 'post'], 'unlink-media' => ['/media/links', 'delete']] as $action => [$path, $method]) {
            $tool = self::description($action);
            $schema = $tool['inputSchema'];
            $operation = ['operationId' => $action, 'summary' => $action, 'description' => $tool['description'], 'tags' => ['webtrees'],
                'responses' => []];
            foreach ([200 => 'Success or no change', 201 => 'Upload stored; pending approval', 202 => 'Change awaits approval', 400 => 'Invalid input', 401 => 'Missing or invalid token', 403 => 'Scope or webtrees privacy/access denied', 404 => 'Record not found', 405 => 'Unsupported method', 409 => 'Pending changes or ambiguous record', 413 => 'Upload too large', 415 => 'Unsupported image', 500 => 'Operation failed; check record before retry'] as $code => $description) {
                $operation['responses'][(string) $code] = ['description' => $description];
            }
            if ($method === 'get' || $method === 'delete') {
                foreach ($schema['properties'] as $key => $property) {
                    $operation['parameters'][] = ['name' => $key, 'in' => 'query', 'required' => in_array($key, $schema['required'], true), 'schema' => $property];
                }
            } else {
                $contentType = 'application/json';
                if ($action === 'upload-media') {
                    unset($schema['properties']['content-base64'], $schema['properties']['filename']);
                    $schema['properties']['file'] = ['type' => 'string', 'format' => 'binary', 'description' => 'JPEG/PNG/GIF/WebP, up to 20 MiB and 40 megapixels; also subject to PHP limits.'];
                    $schema['required'] = ['tree', 'target-xref', 'target-type', 'file'];
                    $contentType = 'multipart/form-data';
                    $operation['description'] = 'Upload actual image bytes as multipart/form-data. A new OBJE record and target link await moderator approval. Each request creates a distinct file; do not blindly retry. Filename is taken from the file part.';
                }
                $operation['requestBody'] = ['required' => true, 'content' => [$contentType => ['schema' => $schema]]];
            }
            $paths[$path][$method] = $operation;
        }
        $paths['/media/download']['get'] = ['operationId' => 'download-media', 'summary' => 'Download a visible media file',
            'description' => 'Authenticated attachment download. Supply filename returned by get-media. External URLs and traversal paths are rejected.', 'tags' => ['webtrees'],
            'parameters' => array_map(fn ($key) => ['name' => $key, 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']], ['tree', 'xref', 'filename']),
            'responses' => ['200' => ['description' => 'File bytes', 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                '403' => ['description' => 'Scope or privacy denied'], '404' => ['description' => 'Visible file not found']]];
        return $paths;
    }

    public static function description(string $action): array
    {
        if ($action === 'upload-media-chunk') {
            $tool = self::description('upload-media');
            $tool['name'] = $action;
            $tool['description'] = 'Upload ordered local image chunks using mcp_write, up to 256 KiB decoded per chunk and 20 MiB total. Send identical metadata on every call. upload-id is 8 hex Unix timestamp seconds followed by 24 random hex digits; start at offset 0. New IDs must be less than one hour old and no more than 300 seconds in the future. next-offset acknowledges stored bytes. final=true on the last chunk verifies the full SHA-256 then creates pending media and target link. Exact chunk retries and exact final replay are safe. Sessions expire one hour after the ID timestamp; final receipts and uncertain commits expire after 24 hours. Expired IDs cannot be reused. Uncertain commits return 409: verify with an administrator, never retry with a new ID. Never invent bytes or expose tokens/base64 in URLs.';
            $tool['inputSchema']['properties'] += [
                'upload-id' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{32}$', 'description' => '8 hex Unix timestamp seconds followed by 24 random hex digits.'],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => MediaInput::REST_LIMIT],
                'total-bytes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MediaInput::REST_LIMIT],
                'sha256' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$'],
                'final' => ['type' => 'boolean'],
            ];
            $tool['inputSchema']['properties']['tree']['maxLength'] = 255;
            $tool['inputSchema']['properties']['target-xref']['pattern'] = '^[A-Za-z0-9_:-]{1,64}$';
            $tool['inputSchema']['properties']['content-base64'] = ['type' => 'string', 'maxLength' => 349528,
                'description' => 'Canonical base64, at most 262144 decoded bytes. Empty allowed only for finalization at total-bytes.'];
            $tool['inputSchema']['required'] = array_merge($tool['inputSchema']['required'], ['upload-id', 'offset', 'total-bytes', 'sha256', 'final']);
            $tool['annotations']['title'] = $action;
            $tool['annotations']['idempotentHint'] = true;
            return $tool;
        }
        $properties = ['tree' => ['type' => 'string', 'description' => 'Exact tree name from get-trees.'],
            'xref' => ['type' => 'string', 'description' => 'Existing OBJE media XREF, without @ signs.']];
        $required = ['tree', 'xref'];
        if (in_array($action, ['upload-media', 'link-media', 'unlink-media'], true)) {
            $properties += ['target-xref' => ['type' => 'string', 'description' => 'Existing target XREF; verify using get-record before writing.'],
                'target-type' => ['type' => 'string', 'enum' => ['INDI', 'FAM', 'SOUR']]];
            $required = array_merge($required, ['target-xref', 'target-type']);
        }
        if (in_array($action, ['upload-media', 'update-media'], true)) {
            foreach (['title', 'note', 'date'] as $key) {
                $properties[$key] = ['type' => 'string', 'maxLength' => 16384, 'description' => $key === 'date'
                    ? 'Optional media date as supplied by user, stored in webtrees _DATE. Omitted preserves it; empty clears it.'
                    : 'Optional ' . $key . '. Omitted preserves existing data; empty clears it.'];
            }
        }
        if ($action === 'upload-media') {
            unset($properties['xref']);
            $required = ['tree', 'target-xref', 'target-type', 'filename', 'content-base64'];
            $properties['filename'] = ['type' => 'string', 'maxLength' => 180, 'description' => 'Basename including JPEG/PNG/GIF/WebP extension, never a path.'];
            $properties['content-base64'] = ['type' => 'string', 'maxLength' => MediaInput::maxBase64Length(),
                'description' => 'Legacy inline transport for actual local image bytes encoded as canonical base64, at most 512 KiB decoded. For larger files use the local MCP bridge with local-path (up to 20 MiB, mcp_write), which transfers every chunk through MCP. Authenticated multipart REST POST /api/media with api_write remains a compatibility fallback only. Use a file/terminal tool to encode it; never invent bytes. No data URL prefix or whitespace.'];
        }
        $description = match ($action) {
            'upload-media' => 'Upload a local image and link it to a verified INDI/FAM/SOUR. First get-trees and get-record. The remote server cannot open local paths, so the local MCP bridge is the standard path for every image: it accepts local-path up to 20 MiB and transfers bounded chunks through MCP with mcp_write. Inline base64 is legacy and only up to 512 KiB decoded; multipart REST is a compatibility fallback, not required for MCP uploads. Never put secrets or base64 in query URLs. On success retain the returned media XREF and do not upload again: BOTH record and link await moderator approval.',
            'get-media' => 'Read visible media filenames, webtrees page URL and pending status. For bytes use authenticated REST GET /media/download with tree, xref and filename; the webtrees page URL is not a public file URL.',
            'update-media' => 'Submit a media metadata change. Omitted fields remain unchanged; empty fields clear one value. Multiple titles/files or notes may require editing in webtrees. Await moderator approval before another write.',
            'link-media' => 'Link approved media to an existing verified person, family or source at record level. An existing link is a no-op only when both records have no pending changes. Pending records return 409 even for a repeated link; await moderator approval.',
            'unlink-media' => 'Remove the record-level media link from the selected target, preserving its other facts and links. Event-level links must be edited in webtrees. Await moderator approval.',
            'delete-media' => 'Request deletion of an unlinked approved media record. Unlink and approve all links first. The file is deliberately retained for pending/rejected changes and shared references; administrator cleanup of unused files is separate.',
        };
        $description = str_replace(['REST POST /media', 'REST GET /media/download'], ['REST POST /api/media', 'REST GET /api/media/download'], $description);
        if ($action === 'get-media' || $action === 'upload-media') {
            $description .= ' Reading pending records requires mcp_read_member and sufficient webtrees user rights; mcp_write or api_read_member does not grant MCP member reads. A privacy-only read may return 404 until approval; retain the upload XREF and do not re-upload.';
        }
        if ($action === 'get-media') {
            $description .= ' For download, filename is the complete relative storage path returned by get-media, including api-media/.../name.png, URL-encoded as a query parameter, not just the basename.';
        }
        return ['name' => $action, 'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
            'annotations' => ['title' => $action, 'readOnlyHint' => $action === 'get-media',
                'destructiveHint' => in_array($action, ['delete-media', 'unlink-media', 'update-media'], true),
                'idempotentHint' => $action === 'get-media', 'openWorldHint' => false]];
    }
}
