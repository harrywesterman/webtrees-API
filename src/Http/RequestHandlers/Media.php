<?php
declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Media as MediaRecord;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Validator;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Schema\Mcp as McpSchema;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\QueryParamValidator;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

class Media implements WebtreesMcpToolRequestHandlerInterface
{
    public const string METHOD_DESCRIPTION = 'Create, inspect, update or delete a webtrees media record.';
    public function __construct(private TreeService $treeService, private StreamFactoryInterface $streamFactory) {}

    #[OA\Post(path: '/' . WebtreesApi::PATH_MEDIA, description: self::METHOD_DESCRIPTION, tags: ['webtrees'])]
    #[OA\Get(path: '/' . WebtreesApi::PATH_MEDIA, description: self::METHOD_DESCRIPTION, tags: ['webtrees'])]
    #[OA\Put(path: '/' . WebtreesApi::PATH_MEDIA, description: self::METHOD_DESCRIPTION, tags: ['webtrees'])]
    #[OA\Delete(path: '/' . WebtreesApi::PATH_MEDIA, description: self::METHOD_DESCRIPTION, tags: ['webtrees'])]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try { return match (strtoupper($request->getMethod())) { 'POST' => $this->create($request), 'GET' => str_ends_with($request->getUri()->getPath(), '/' . WebtreesApi::PATH_MEDIA_DOWNLOAD) ? $this->download($request) : $this->inspect($request), 'PUT' => $this->update($request), 'DELETE' => $this->remove($request), default => api_response('HTTP method not supported.', 405) }; }
        catch (Throwable $e) { return api_response($e->getMessage(), 500); }
    }

    private function tree(ServerRequestInterface $request): array|ResponseInterface
    {
        $name = Validator::queryParams($request)->string('tree', '');
        $check = QueryParamValidator::validateTreeName($this->treeService, $name);
        return $check->getStatusCode() === 200 ? [$this->treeService->all()[$name], $name] : $check;
    }
    private function writeAccess($tree): ?ResponseInterface { $r = CheckAccess::checkUserWriteAccess($tree); return $r->getStatusCode() === 200 ? null : $r; }
    private function record($xref, $tree, bool $edit = false): array|ResponseInterface
    { $r = Registry::gedcomRecordFactory()->make($xref, $tree); if ($r === null) return api_response('Record not found.', 404); $a = CheckAccess::checkRecordAccess($r, $edit); return $a->getStatusCode() === 200 ? [$r, $a] : $a; }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = $this->tree($request); if ($ctx instanceof ResponseInterface) return $ctx; [$tree] = $ctx; if (($a = $this->writeAccess($tree)) !== null) return $a;
        $q = Validator::queryParams($request); $type = strtoupper($q->string('target-type', '')); $target = $this->record($q->string('target-xref', ''), $tree, true);
        if ($target instanceof ResponseInterface) return $target; [$targetRecord] = $target; if ($targetRecord->tag() !== $type || !in_array($type, ['INDI', 'FAM', 'SOUR'], true)) return api_response('Invalid target record.', 400);
        $file = $request->getUploadedFiles()['file'] ?? null; if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) return api_response('A valid multipart file field is required.', 400);
        $mime = strtolower((string) $file->getClientMediaType()); if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff'], true)) return api_response('Unsupported image type.', 415);
        $base = trim((string) $tree->getPreference('MEDIA_DIRECTORY'), '/'); $name = $this->safeName((string) $file->getClientFilename()); $path = $base . '/' . $name; $fs = Registry::filesystem()->data(); if ($fs->fileExists($path)) return api_response('Media file already exists.', 409);
        $fs->writeStream($path, $file->getStream()->detach()); $qv = fn(string $key): string => $q->string($key, ''); $gedcom = "1 FILE $path\n2 FORM " . strtoupper(substr($mime, 6)); foreach (['title' => 'TITL', 'date' => 'DATE', 'note' => 'NOTE'] as $k => $tag) if (($v = $qv($k)) !== '') $gedcom .= "\n1 $tag $v";
        try { $media = $tree->createRecord("0 @@ MEDIA\n$gedcom"); $targetRecord->updateRecord($targetRecord->gedcom() . "\n1 OBJE @" . $media->xref() . '@', false); return api_response(['xref' => $media->xref(), 'file' => $path], 201); } catch (Throwable $e) { if ($fs->fileExists($path)) $fs->delete($path); throw $e; }
    }
    private function inspect(ServerRequestInterface $request): ResponseInterface
    { $ctx = $this->tree($request); if ($ctx instanceof ResponseInterface) return $ctx; [$tree] = $ctx; $r = $this->record(Validator::queryParams($request)->string('xref', ''), $tree); if ($r instanceof ResponseInterface) return $r; [$record] = $r; if (!$record instanceof MediaRecord) return api_response('Media record not found.', 404); return api_response(['xref' => $record->xref(), 'gedcom' => $record->gedcom(), 'url' => $record->mediaUrl()], 200); }
    private function update(ServerRequestInterface $request): ResponseInterface
    { $ctx = $this->tree($request); if ($ctx instanceof ResponseInterface) return $ctx; [$tree] = $ctx; if (($a = $this->writeAccess($tree)) !== null) return $a; $q = Validator::queryParams($request); $r = $this->record($q->string('xref', ''), $tree, true); if ($r instanceof ResponseInterface) return $r; [$record] = $r; if (!$record instanceof MediaRecord) return api_response('Media record not found.', 404); $gedcom = preg_replace('/\n1 (TITL|DATE|NOTE) .*$/m', '', $record->gedcom()); foreach (['title' => 'TITL', 'date' => 'DATE', 'note' => 'NOTE'] as $k => $tag) if (($v = $q->string($k, '')) !== '') $gedcom .= "\n1 $tag $v"; $record->updateRecord($gedcom, false); return api_response(['xref' => $record->xref(), 'updated' => true], 200); }
    private function remove(ServerRequestInterface $request): ResponseInterface
    { $ctx = $this->tree($request); if ($ctx instanceof ResponseInterface) return $ctx; [$tree] = $ctx; if (($a = $this->writeAccess($tree)) !== null) return $a; $q = Validator::queryParams($request); $r = $this->record($q->string('xref', ''), $tree, true); if ($r instanceof ResponseInterface) return $r; [$record] = $r; if (!$record instanceof MediaRecord) return api_response('Media record not found.', 404); $base = trim((string) $tree->getPreference('MEDIA_DIRECTORY'), '/'); if (preg_match('/^1 FILE (.+)$/m', $record->gedcom(), $m)) { $path = str_replace('\\', '/', $m[1]); if (str_starts_with($path, $base . '/') && !str_contains(substr($path, strlen($base) + 1), '..')) Registry::filesystem()->data()->delete($path); } $record->deleteRecord(); return api_response(['deleted' => $record->xref()], 200); }
    private function safeName(string $name): string { $name = basename(str_replace('\\', '/', $name)); $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'upload'; return ltrim($name, '.'); }
    public static function getMcpToolDescription(): array { return ['name' => WebtreesApi::PATH_MEDIA, 'description' => self::METHOD_DESCRIPTION, 'inputSchema' => ['type' => 'object', 'properties' => ['tree' => McpSchema::TREE, 'xref' => McpSchema::XREF, 'title' => ['type' => 'string'], 'note' => ['type' => 'string'], 'date' => ['type' => 'string']], 'required' => ['tree']]]; }
    }
    private function download(ServerRequestInterface $request): ResponseInterface
    { $ctx = $this->tree($request); if ($ctx instanceof ResponseInterface) return $ctx; [$tree] = $ctx; $r = $this->record(Validator::queryParams($request)->string('xref', ''), $tree); if ($r instanceof ResponseInterface) return $r; [$record] = $r; if (!$record instanceof MediaRecord) return api_response('Media record not found.', 404); if (preg_match('/^1 FILE (.+)$/m', $record->gedcom(), $m) !== 1) return api_response('Media file not found.', 404); $base = trim((string) $tree->getPreference('MEDIA_DIRECTORY'), '/'); $path = str_replace('\\', '/', $m[1]); if (!str_starts_with($path, $base . '/') || str_contains(substr($path, strlen($base) + 1), '..')) return api_response('Unsafe media path.', 400); $fs = Registry::filesystem()->data(); if (!$fs->fileExists($path)) return api_response('Media file not found.', 404); $stream = $fs->readStream($path); return $this->streamFactory->createResponse()->withHeader('content-type', 'application/octet-stream')->withBody($this->streamFactory->createStreamFromResource($stream)); }
