<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use DomainException;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Media as MediaRecord;
use Fisharebest\Webtrees\MediaFile;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaInput;
use Jefferson49\Webtrees\Module\WebtreesApi\OAuth2\Repositories\ScopeRepository as Scopes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Response;
use Throwable;
use Jefferson49\Webtrees\Authorization\Auth;
use Jefferson49\Webtrees\Helpers\Authorization;
use Jefferson49\Webtrees\Helpers\Functions;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

/** Transport adapter and shared media operations. Never auto-accept genealogy edits. */
class Media implements RequestHandlerInterface
{
    public function __construct(private TreeService $trees, private LinkedRecordService $links) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $action = match ($request->getAttribute('media_http_method', $request->getMethod())) {
            'GET' => 'get-media', 'POST' => 'upload-media',
            'PUT' => 'update-media', 'DELETE' => 'delete-media', default => '',
        };
        return $this->execute($request, $action);
    }

    public function execute(ServerRequestInterface $request, string $action): ResponseInterface
    {
        return $this->perform($request, $action)->withHeader('Cache-Control', 'private, no-store');
    }

    private function perform(ServerRequestInterface $request, string $action): ResponseInterface
    {
        try {
            if (!in_array($action, ['upload-media', 'get-media', 'download-media', 'update-media', 'link-media', 'unlink-media', 'delete-media'], true)) {
                throw new DomainException('Method not allowed.', 405);
            }
            $write = !in_array($action, ['get-media', 'download-media'], true);
            $mcp = $request->getAttribute('media_mcp', false) === true;
            $scopes = $request->getAttribute('oauth_scopes', []);
            $allowed = $write ? [$mcp ? Scopes::SCOPE_MCP_WRITE : Scopes::SCOPE_API_WRITE]
                : ($mcp ? [Scopes::SCOPE_MCP_READ_MEMBER, Scopes::SCOPE_MCP_READ_PRIVACY] : [Scopes::SCOPE_API_READ_MEMBER, Scopes::SCOPE_API_READ_PRIVACY]);
            if (!array_intersect($allowed, $scopes)) {
                throw new DomainException('Insufficient media permissions.', 403);
            }
            $input = $request->getQueryParams();
            if ($write && !$mcp) {
                $postLimit = ini_parse_quantity((string) ini_get('post_max_size'));
                $length = $request->getHeaderLine('Content-Length');
                if ($postLimit > 0 && ctype_digit($length) && (float) $length > $postLimit) {
                    throw new DomainException('Request exceeds PHP post_max_size; reduce the upload or contact the administrator.', 413);
                }
                $body = $request->getParsedBody();
                if ($body === null && str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
                    $body = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
                }
                if ($body !== null && !is_array($body)) {
                    throw new DomainException('Expected an object request body.', 400);
                }
                foreach ($body ?? [] as $key => $value) {
                    if (array_key_exists($key, $input) && $input[$key] !== $value) {
                        throw new DomainException('Conflicting query/body field: ' . $key, 400);
                    }
                    $input[$key] = $value;
                }
            }
            $tree = $this->trees->all()[MediaInput::text($input, 'tree')] ?? null;
            if (!$tree instanceof Tree) {
                throw new DomainException('Tree not found.', 404);
            }
            $privacy = !$write && !in_array($mcp ? Scopes::SCOPE_MCP_READ_MEMBER : Scopes::SCOPE_API_READ_MEMBER, $scopes, true);
            if ($write) {
                $this->check(CheckAccess::checkUserWriteAccess($tree));
            } elseif ($privacy) {
                $this->check(CheckAccess::checkTreePrivacy($tree));
            }
            if ($action === 'upload-media') {
                return $this->upload($request, $tree, $input, $mcp);
            }
            $record = $this->record($tree, MediaInput::text($input, 'xref'), $write, $privacy);
            if (!$record instanceof MediaRecord) {
                throw new DomainException('XREF must identify an OBJE media record.', 400);
            }
            if ($action === 'get-media') {
                // mediaFiles() applies fact privacy; never return raw GEDCOM or private FILE paths.
                $files = [];
                foreach ($this->visibleFiles($record, $privacy) as $file) {
                    $files[] = ['filename' => $file->filename(), 'title' => $file->title()];
                }
                $metadata = [];
                $level = $privacy ? Auth::PRIV_PRIVATE : Authorization::accessLevelForTree($tree);
                foreach (Functions::getRecordFacts($record, ['NOTE', '_DATE'], false, $level, true) as $fact) {
                    $metadata[] = ['tag' => $fact->tag(), 'value' => $fact->value()];
                }
                return api_response(['xref' => $record->xref(), 'files' => $files, 'metadata' => $metadata, 'url' => $record->url(), 'pending' => $record->isPendingAddition()], 200);
            }
            if ($action === 'download-media') {
                $name = MediaInput::text($input, 'filename');
                MediaInput::path($name);
                $visible = $this->visibleFiles($record, $privacy)->filter(fn ($file) => $file->filename() === $name);
                if ($visible->isEmpty()) {
                    throw new DomainException('Visible media file not found; provide filename from get-media.', 404);
                }
                $filesystem = $tree->mediaFilesystem();
                if (!$filesystem->fileExists($name)) {
                    throw new DomainException('Media file not found on storage.', 404);
                }
                $stream = $filesystem->readStream($name);
                return new Response(200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'], $stream);
            }
            $this->editable($record);
            if ($action === 'update-media') {
                $gedcom = $record->gedcom();
                if (array_key_exists('title', $input)) {
                    // A title belongs to FILE, not to the level-zero media record.
                    preg_match_all('/\n1 FILE[^\n]*(?:\n[2-9] [^\n]*)*/', $gedcom, $matches);
                    if (count($matches[0]) !== 1) {
                        throw new DomainException('Title updates require exactly one FILE fact.', 409);
                    }
                    $file = MediaInput::replace($matches[0][0], 2, 'TITL', MediaInput::text($input, 'title'));
                    $gedcom = str_replace($matches[0][0], $file, $gedcom);
                }
                foreach (['note' => 'NOTE', 'date' => '_DATE'] as $key => $tag) {
                    if (array_key_exists($key, $input)) {
                        $gedcom = MediaInput::replace($gedcom, 1, $tag, MediaInput::text($input, $key));
                    }
                }
                if ($gedcom === $record->gedcom()) {
                    return api_response(['xref' => $record->xref(), 'changed' => false], 200);
                }
                $this->mutate([$record], fn () => $record->updateRecord($gedcom, true));
            } elseif ($action === 'delete-media') {
                // Requiring explicit unlink avoids modifying records the caller has not selected.
                if ($this->links->allLinkedRecords($record)->isNotEmpty()) {
                    throw new DomainException('Unlink this media in webtrees or with unlink-media, then approve those changes before deleting.', 409);
                }
                $this->mutate([$record], function () use ($record, $tree) {
                    // The link index excludes pending GEDCOM; also reject pending references.
                    $reference = '@' . $record->xref() . '@';
                    foreach (DB::table('change')->where('gedcom_id', $tree->id())->where('status', 'pending')->get(['new_gedcom']) as $change) {
                        if (str_contains($change->new_gedcom, $reference)) {
                            throw new DomainException('Pending changes reference this media. Review them before deleting.', 409);
                        }
                    }
                    $record->deleteRecord();
                });
                return api_response(['xref' => $record->xref(), 'pending' => true, 'file-retained' => true,
                    'message' => 'Deletion awaits moderator approval. Files are retained for shared references and rejection; an administrator may clean unused files in webtrees.'], 202);
            } else {
                $target = $this->target($tree, $input);
                $line = "\n1 OBJE @" . $record->xref() . '@';
                $old = $target->gedcom();
                $pattern = '/\n1 OBJE @' . preg_quote($record->xref(), '/') . '@(?=\n|$)(?:\n[2-9] [^\n]*)*/';
                $linked = preg_match($pattern, $old) === 1;
                // A repeated link or unlink is a true no-op. It must not be
                // blocked merely because the target has an unrelated pending
                // change; no record is written in this branch.
                if (($action === 'link-media' && $linked) || ($action === 'unlink-media' && !$linked)) {
                    return api_response(['xref' => $record->xref(), 'target-xref' => $target->xref(), 'changed' => false], 200);
                }
                $this->editable($target);
                $new = $action === 'link-media' ? ($linked ? $old : $old . $line) : preg_replace($pattern, '', $old);
                $this->mutate([$record, $target], fn () => $target->updateRecord($new, true));
            }
            return api_response(['xref' => $record->xref(), 'pending' => true, 'message' => 'Change submitted. A moderator must approve it in webtrees.'], 202);
        } catch (DomainException $e) {
            return api_response($e->getMessage(), $e->getCode() ?: 400);
        } catch (\JsonException) {
            return api_response('Invalid JSON request body.', 400);
        } catch (Throwable) {
            return api_response('Media operation failed. No automatic retry: verify the record before retrying.', 500);
        }
    }

    private function upload(ServerRequestInterface $request, Tree $tree, array $input, bool $mcp): ResponseInterface
    {
        $target = $this->target($tree, $input);
        $this->editable($target);
        if ($mcp) {
            $encoded = $input['content-base64'] ?? null;
            if (!is_string($encoded)) {
                throw new DomainException('content-base64 is required. Read and encode the local file using client file tools.', 400);
            }
            if (strlen($encoded) > MediaInput::maxBase64Length()) {
                return api_response([
                    'error' => 'inline_upload_too_large',
                    'maxInlineBytes' => MediaInput::MCP_INLINE_LIMIT,
                    'multipartEndpoint' => '/api/media',
                    'requiredScope' => Scopes::SCOPE_API_WRITE,
                    'message' => 'Send the local image as multipart/form-data to POST /api/media. Do not place base64 or secrets in the MCP request or a URL.',
                ], 413);
            }
            $name = MediaInput::filename(MediaInput::text($input, 'filename'));
            $bytes = MediaInput::base64($encoded);
        } else {
            $file = $request->getUploadedFiles()['file'] ?? null;
            if ($file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new DomainException('File exceeds the PHP upload limit; reduce it or contact the administrator.', 413);
            }
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                throw new DomainException('A successful multipart file upload is required; check PHP upload/post limits.', 400);
            }
            $name = MediaInput::filename($file->getClientFilename() ?? '');
            $stream = $file->getStream();
            $bytes = '';
            while (!$stream->eof() && strlen($bytes) <= MediaInput::REST_LIMIT) {
                $part = $stream->read(min(65536, MediaInput::REST_LIMIT + 1 - strlen($bytes)));
                if ($part === '') { break; }
                $bytes .= $part;
            }
        }
        $mime = MediaInput::image($bytes, $name, $mcp ? MediaInput::MCP_INLINE_LIMIT : MediaInput::REST_LIMIT);
        // A random directory preserves the readable basename without ever replacing an existing file.
        $path = 'api-media/' . bin2hex(random_bytes(16)) . '/' . $name;
        $fs = $tree->mediaFilesystem();
        if ($fs->fileExists($path)) {
            throw new DomainException('Upload path collision; retry with a new request.', 409);
        }
        $gedcom = "0 @@ OBJE\n1 FILE " . $path . "\n2 FORM " . strtoupper(pathinfo($name, PATHINFO_EXTENSION));
        foreach (['title' => [2, 'TITL'], 'note' => [1, 'NOTE'], 'date' => [1, '_DATE']] as $key => [$level, $tag]) {
            $value = MediaInput::text($input, $key);
            if ($value !== '') { $gedcom .= "\n" . MediaInput::field($level, $tag, $value); }
        }
        try {
            $fs->write($path, $bytes);
            $record = $this->mutate([$target], function () use ($tree, $target, $gedcom) {
                $record = $tree->createRecord($gedcom);
                $target->updateRecord($target->gedcom() . "\n1 OBJE @" . $record->xref() . '@', true);
                return $record;
            });
        } catch (Throwable $e) {
            // Only remove this request's randomly allocated file, never a caller-supplied path.
            try { $fs->delete($path); } catch (Throwable) {
                error_log('webtrees API: failed upload left an unused api-media file; administrator cleanup required.');
            }
            throw $e;
        }
        return api_response(['xref' => $record->xref(), 'filename' => $path, 'mime-type' => $mime,
            'target-xref' => $target->xref(), 'pending' => true,
            'message' => 'Upload stored; approve BOTH the media record and target link in webtrees. Do not repeat the upload.'], 201);
    }

    private function target(Tree $tree, array $input): GedcomRecord
    {
        $record = $this->record($tree, MediaInput::text($input, 'target-xref'), true, false);
        $type = MediaInput::text($input, 'target-type');
        if (!in_array($type, ['INDI', 'FAM', 'SOUR'], true) || $record->tag() !== $type) {
            throw new DomainException('target-type must match the target INDI, FAM or SOUR record.', 400);
        }
        return $record;
    }

    private function record(Tree $tree, string $xref, bool $edit, bool $privacy): GedcomRecord
    {
        if (!preg_match('/^[A-Za-z0-9_:-]{1,64}$/D', $xref)) {
            throw new DomainException('Invalid XREF.', 400);
        }
        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        if ($record === null) { throw new DomainException('Record not found.', 404); }
        $this->check(CheckAccess::checkRecordAccess($record, $edit));
        if ($privacy) { $this->check(CheckAccess::checkRecordAccess($record, false, true)); }
        if ($record->isPendingDeletion()) { throw new DomainException('Record has a pending deletion.', 409); }
        return $record;
    }

    private function editable(GedcomRecord $record): void
    {
        if ($record->isPendingAddition()) {
            throw new DomainException('Record has pending changes. Have a moderator review them before another media edit.', 409);
        }
        $all = Functions::getRecordFacts($record, [], false, Auth::PRIV_HIDE, true);
        $visible = Functions::getRecordFacts($record, [], false, Authorization::accessLevelForTree($record->tree()), true);
        if ($all->count() !== $visible->count()) {
            throw new DomainException('This record contains restricted facts; edit it in webtrees.', 403);
        }
    }

    private function visibleFiles(MediaRecord $record, bool $privacy): \Illuminate\Support\Collection
    {
        $level = $privacy ? Auth::PRIV_PRIVATE : Authorization::accessLevelForTree($record->tree());
        return Functions::getRecordFacts($record, ['FILE'], false, $level, true)
            ->map(fn ($fact) => new MediaFile($fact->gedcom(), $record));
    }

    /** Serialize media writes per tree and reject pending changes missed by cached record objects. */
    private function mutate(array $records, callable $write): mixed
    {
        return DB::connection()->transaction(function () use ($records, $write) {
            $tree = $records[0]->tree();
            DB::table('gedcom')->where('gedcom_id', $tree->id())->lockForUpdate()->first();
            foreach ($records as $record) {
                if (DB::table('change')->where('gedcom_id', $tree->id())->where('xref', $record->xref())->where('status', 'pending')->exists()) {
                    throw new DomainException('Concurrent or pending record change; review it before retrying.', 409);
                }
            }
            return $write();
        });
    }

    private function check(ResponseInterface $response): void
    {
        if ($response->getStatusCode() !== 200) {
            throw new DomainException('Access denied by webtrees record, tree or privacy settings.', $response->getStatusCode());
        }
    }
}
