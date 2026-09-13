# Media repair review

All changes are uncommitted. No DreamHost connection, deployment, commit or push was performed during this repair.

## Review order

1. `src/Http/RequestHandlers/Media.php`: scope checks, shared operations, pending-change protection, transactions, retained files on deletion.
2. `src/Http/Validation/MediaInput.php`: content decoding, size/memory limits, safe paths, GEDCOM continuation and field preservation.
3. Middleware and the six MCP adapters: preserved multipart/methods, discovery, scope lists, 202 success and omission of raw payloads from debug logs.
4. `src/Http/Schema/MediaTools.php`: shared tool and OpenAPI definitions. Runtime regeneration merges these definitions; tests compare the checked-in JSON.
5. `resources/media-api.md`: English/Dutch operation instructions and Codex/OpenCode examples, including client payload limitations.

## Deliberate behavior

- JPEG/PNG/GIF/WebP only, 5 MiB MCP / 20 MiB REST, 40 megapixels and available decoding memory. TIFF/PDF/SVG are not accepted.
- Random per-upload directories keep repeated filenames from overwriting files. Do not blindly retry uploads.
- Media and target links both await review. Further writes refuse pending edits and restricted facts.
- Deletion is a pending record deletion, not immediate file destruction. Shared files and rejected changes retain their bytes. Administrator cleanup is separate.
- `_DATE` is a webtrees-supported custom media date field. Notes and titles preserve omitted values and reject ambiguous multiple values.
- The lockfile's old helper version lacked `registerRoute`. Only that package was updated (1.2.45 to 1.4.60). PHP 8.4 now matches the README/locked dependencies; GD and fileinfo are explicit requirements.

## Verification and remaining limits

- PHP 8.4.23 was unpacked locally into `/tmp`; 110 PHP source/test files passed syntax checks.
- Handler tests use real PSR-7, Flysystem and SQLite transactions, with webtrees record/access doubles. They cover upload targets, MIME/extension/path checks, scopes/privacy, rollback, pending conflicts, metadata, link/unlink, downloads and file retention.
- Contract tests load webtrees 2.2.6 and module dependencies. They cover real method availability, middleware, tools/list, dispatch, scope classification, 202 MCP results and checked-in OpenAPI consistency.
- Composer validation succeeds with existing-style open-ended version warnings; dependency resolution reports no vulnerability advisories. The temporary PHP build lacks sodium, so dependency installation ignored that platform requirement for this local test environment only. Production requirements are not bypassed in the repository.
- No production database, token issuance, webtrees approval UI, or live Codex/OpenCode session was tested. A 2.3 runtime was not exercised; compatibility paths use the existing version-aware helper and public APIs.
- The per-tree lock serializes these media API writes, not unrelated webtrees editor operations. PHP/filesystem failure after a process crash can leave an unused file for cleanup; filesystem/database operations cannot be one atomic transaction.
- Image bytes remain intact. This does not remove EXIF metadata or provide malware scanning. Authorization and privacy should be checked again in the eventual staging acceptance test.
