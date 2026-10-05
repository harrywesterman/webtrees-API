<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Http\RequestHandlers;

use DomainException;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\CheckAccess;
use Jefferson49\Webtrees\Module\WebtreesApi\Http\Validation\MediaInput;
use Jefferson49\Webtrees\Module\WebtreesApi\OAuth2\Repositories\ScopeRepository as Scopes;
use Jefferson49\Webtrees\Module\WebtreesApi\WebtreesApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function Jefferson49\Webtrees\Module\WebtreesApi\Helpers\api_response;

/**
 * Administrator cleanup for unreferenced api-media files. Deletion is opt-in;
 * the default is a dry run so a moderator can review what would be removed.
 */
final class MediaCleanup implements RequestHandlerInterface
{
    public function __construct(private TreeService $trees) {}

    #[OA\Post(
        path: '/' . WebtreesApi::PATH_MEDIA_CLEANUP,
        description: 'List or delete api-media files that no approved media record references. Requires the api_import scope.',
        tags: ['webtrees'],
        responses: [
            new OA\Response(response: 200, description: 'Cleanup report'),
            new OA\Response(response: 403, description: 'Scope or access denied'),
            new OA\Response(response: 404, description: 'Tree not found'),
        ],
    )]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $params = $request->getQueryParams();
            $body = $request->getParsedBody();
            if (is_array($body)) {
                $params = array_replace($params, $body);
            }
            $scopes = $request->getAttribute('oauth_scopes', []);
            if (!array_intersect([Scopes::SCOPE_API_IMPORT], $scopes)) {
                throw new DomainException('Media cleanup requires the api_import scope.', 403);
            }
            $tree = $this->trees->all()[MediaInput::text($params, 'tree')] ?? null;
            if (!$tree instanceof Tree) {
                throw new DomainException('Tree not found.', 404);
            }
            $this->check(CheckAccess::checkUserWriteAccess($tree));
            $dry_run = !array_key_exists('dry-run', $params) || filter_var($params['dry-run'], FILTER_VALIDATE_BOOL);
            $days = max(0, (int) ($params['older-than-days'] ?? 30));
            $cutoff = time() - $days * 86400;

            $referenced = array_flip(DB::table('media_file')->where('m_file', $tree->id())
                ->where('multimedia_file_refn', 'like', 'api-media/%')->pluck('multimedia_file_refn')->all());
            $filesystem = $tree->mediaFilesystem();
            $deleted = [];
            $recent = [];
            foreach ($filesystem->listContents('api-media', true) as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $path = $item->path();
                if (isset($referenced[$path])) {
                    continue;
                }
                if ($item->lastModified() > $cutoff) {
                    $recent[] = $path;
                    continue;
                }
                if (!$dry_run) {
                    $filesystem->delete($path);
                }
                $deleted[] = $path;
            }
            return api_response([
                'tree' => $tree->name(),
                'dry-run' => $dry_run,
                'older-than-days' => $days,
                'referenced' => count($referenced),
                'removed' => $deleted,
                'skipped-recent' => $recent,
                'message' => $dry_run ? 'Dry run only. Repeat with dry-run=false to delete the listed files.' : 'Unreferenced files removed.',
            ], 200);
        } catch (DomainException $e) {
            return api_response($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable) {
            return api_response('Media cleanup failed.', 500);
        }
    }

    private function check(ResponseInterface $response): void
    {
        if ($response->getStatusCode() !== 200) {
            throw new DomainException('Access denied by webtrees record, tree or privacy settings.', $response->getStatusCode());
        }
    }
}
