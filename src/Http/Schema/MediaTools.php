<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema;

final class MediaTools
{
    public const array ACTIONS = ['upload-media', 'get-media', 'update-media', 'link-media', 'unlink-media', 'delete-media'];

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
            $properties['content-base64'] = ['type' => 'string', 'maxLength' => 6990508,
                'description' => 'Actual local file bytes encoded as canonical base64, at most 5 MiB decoded. Use a file/terminal tool to encode it; never invent bytes. No data URL prefix or whitespace.'];
        }
        $description = match ($action) {
            'upload-media' => 'Upload a local image and link it to a verified INDI/FAM/SOUR. First get-trees and get-record. Read and base64-encode the actual file using your client file tools; the remote server cannot open local paths. If the client cannot safely pass the base64 payload, use authenticated multipart REST POST /media (up to 20 MiB) from a local script. Never put secrets or base64 in query URLs. On success retain the returned media XREF and do not upload again: BOTH record and link await moderator approval.',
            'get-media' => 'Read visible media filenames, webtrees page URL and pending status. For bytes use authenticated REST GET /media/download with tree, xref and filename; the webtrees page URL is not a public file URL.',
            'update-media' => 'Submit a media metadata change. Omitted fields remain unchanged; empty fields clear one value. Multiple titles/files or notes may require editing in webtrees. Await moderator approval before another write.',
            'link-media' => 'Link approved media to an existing verified person, family or source at record level. An existing link is a no-op. Await moderator approval.',
            'unlink-media' => 'Remove the record-level media link from the selected target, preserving its other facts and links. Event-level links must be edited in webtrees. Await moderator approval.',
            'delete-media' => 'Request deletion of an unlinked approved media record. Unlink and approve all links first. The file is deliberately retained for pending/rejected changes and shared references; administrator cleanup of unused files is separate.',
        };
        return ['name' => $action, 'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
            'annotations' => ['title' => $action, 'readOnlyHint' => $action === 'get-media',
                'destructiveHint' => in_array($action, ['delete-media', 'unlink-media', 'update-media'], true),
                'idempotentHint' => $action === 'get-media', 'openWorldHint' => false]];
    }
}
