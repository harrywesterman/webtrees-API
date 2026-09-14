# Media API — review and client guide

This fork adds image uploads to the existing webtrees module. Deployment and live acceptance results are recorded separately from this client guide.

## Contract

| Operation | REST | MCP tool |
| --- | --- | --- |
| Upload and link | `POST /media` (multipart) | `upload-media` (base64) |
| Visible metadata | `GET /media?tree=…&xref=…` | `get-media` |
| Change metadata | `PUT /media` (JSON) | `update-media` |
| Link approved media | `POST /media/links` (JSON) | `link-media` |
| Unlink at record level | `DELETE /media/links` (query parameters) | `unlink-media` |
| Request record deletion | `DELETE /media?tree=…&xref=…` | `delete-media` |
| Download visible file | `GET /media/download?tree=…&xref=…&filename=…` | Use authenticated REST after `get-media` |

Paths are relative to the module's API base URL shown in its control panel. The MCP URL is a separate endpoint, also shown there. Do not derive either URL from a filesystem path.

All writes require `api_write` or `mcp_write`, an editor technical user, and automatic acceptance disabled. Reads require the respective `*_read_member` or `*_read_privacy` scope. Privacy-only reads check tree and record privacy and filter individual facts. Read scopes never authorize writes. Existing token issuance remains unchanged.

Upload fields: `tree`, `target-xref`, `target-type` (`INDI`, `FAM`, `SOUR`), and optional `title`, `note`, `date`. REST takes a multipart `file`; MCP instead takes `filename` and `content-base64`. Target XREFs have no `@` signs. A target with pending changes must be reviewed first.

JPEG, PNG, GIF and WebP are supported. Content must decode as an image and match its extension. PDF, SVG and TIFF are rejected in this version. Limits: 5 MiB decoded via MCP, 20 MiB via REST, 40 megapixels, available decoding memory, and the host's PHP upload/post limits. Base64 must be canonical with no whitespace or data-URL prefix. It is never accepted in a REST upload URL.

Files use `api-media/<random-id>/<normalized-basename>` inside the tree media filesystem. `FILE` stores that relative path, not a server path or the tree media-directory prefix. Repeated basenames get separate paths; uploads are not idempotent. Titles use `OBJE:FILE:TITL`, notes use `OBJE:NOTE`, dates use the webtrees-supported custom `OBJE:_DATE` field. Date text is retained as provided; it is not normalized to a calendar date.

Metadata updates preserve omitted fields. Empty strings clear a field. Ambiguous repeated fields, multiple files for title edits, restricted facts and pending changes return an error rather than replacing unrelated data. Multi-line values become GEDCOM `CONT` lines. Links are at record level; event-level links remain a webtrees editor operation.

Upload returns HTTP 201 and `{xref, filename, mime-type, target-xref, pending, message}`. Writes return 202 with `pending: true`; no-op edits return 200 with `changed: false`. Both the uploaded media record AND the target link need approval. Approve them together; approval of only the link can temporarily leave a dangling reference. A moderator rejecting the upload may leave an unused file for administrator cleanup.

Deletion refuses linked records and pending references. It submits a pending record deletion and always retains the file (`file-retained: true`), including files shared with other media records or trees. There is no immediate binary deletion endpoint. Administrators can clean genuinely unused files through webtrees after review. Rollback of a failed upload removes only that upload's random file and rolls back its record/link database changes.

Common errors: 400 invalid input; 403 scopes/privacy/editor policy; 404 unknown record/file; 409 pending changes, existing references or ambiguous metadata; 413 size/memory limit; 415 unsupported image. After a 500 or transport timeout, inspect webtrees before retrying: a lost response does not prove the operation failed.

## Codex and OpenCode

Keep the bearer token in `WEBTREES_TOKEN` in the process environment of the client. Obtain it using the module's existing control-panel/token workflow. Do not paste it into conversations or commit it. For REST fallback, provision the additional REST scope explicitly; an MCP-only token does not authorize REST.

Codex `config.toml` example (replace the example endpoint with the control-panel MCP URL):

```toml
[mcp_servers.webtrees]
url = "https://example.net/mcp"
bearer_token_env_var = "WEBTREES_TOKEN"
```

OpenCode `opencode.json` example for the documented `mcp.<name>` configuration layout:

```json
{
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    "webtrees": {
      "type": "remote",
      "url": "https://example.net/mcp",
      "oauth": false,
      "headers": { "Authorization": "Bearer {env:WEBTREES_TOKEN}" }
    }
  }
}
```

Use an existing module bearer token, not interactive OAuth discovery: this module uses client credentials. OpenCode v2 documents a different `mcp.servers.<name>` layout; use the schema matching your installed version. Sources: [Codex MCP configuration](https://developers.openai.com/codex/mcp/), [OpenCode MCP configuration](https://opencode.ai/docs/mcp-servers/), [OpenCode v2](https://opencode.ai/v2/docs/mcp-servers).

Reconnect the MCP server after an update so the client refreshes `tools/list`. Expect six media tools with detailed descriptions, required input fields and read/write annotations. No client configuration is changed by this repository.

Suggested instruction for either agent:

> For webtrees images, use the media tools, not add-unlinked-record or modify-record. First identify the exact tree and verify the intended person/family/source. Read the actual local image with a file or terminal tool and encode its bytes; never invent base64. The server cannot open my local path. Call upload-media once, retain its XREF, and report the pending approval of both media and link. Use get-media to inspect visible metadata. If the payload exceeds tool/context limits, use a local multipart REST script with a separately authorized REST token; never print secrets or large base64 into chat. Do not silently resize or recompress the original. Stop on pending changes and let a moderator review them.

Large base64 strings may exceed an AI client's practical tool limits even below the server's 5 MiB limit. The server limit is not a guarantee that the model can safely carry that payload in its context. Transfer bytes programmatically or use REST, not manual copying through chat.

Example `tools/call` structure (the base64 placeholder must be replaced programmatically with actual file contents):

```json
{
  "jsonrpc": "2.0",
  "id": 10,
  "method": "tools/call",
  "params": {
    "name": "upload-media",
    "arguments": {
      "tree": "test",
      "target-xref": "I123",
      "target-type": "INDI",
      "filename": "portrait.jpg",
      "content-base64": "<actual base64 bytes>",
      "title": "Portrait"
    }
  }
}
```

REST uploads send all fields as multipart, not query parameters. For example, a client script using Python `requests` can take the endpoint/token from its environment and avoid printing either the token or image contents:

```python
import os
import requests

with open("portrait.jpg", "rb") as image:
    response = requests.post(
        os.environ["WEBTREES_API_URL"].rstrip("/") + "/media",
        headers={"Authorization": "Bearer " + os.environ["WEBTREES_TOKEN"]},
        data={"tree": "test", "target-xref": "I123", "target-type": "INDI",
              "title": "Portrait"},
        files={"file": ("portrait.jpg", image, "image/jpeg")},
        timeout=60,
        allow_redirects=False,
    )
    response.raise_for_status()
    print(response.text)
```

Use an HTTPS API URL. The example needs the optional `requests` package and an existing approved target; running it creates pending changes. It is not run by the tests.

## Nederlandse werkwijze

1. Geef Codex of OpenCode toegang tot het lokale afbeeldingsbestand en verbind de bestaande MCP-server met een token uit webtrees.
2. Laat de AI eerst de stamboomnaam en het doelrecord controleren. Een foto kan aan een persoon, familie of bron worden gekoppeld.
3. Laat `upload-media` de echte bestandsinhoud versturen. Een lokaal pad alleen is onvoldoende. Voor grote bestanden kan een lokaal script de multipart REST-route gebruiken, mits het token ook REST-rechten heeft.
4. De AI geeft het media-XREF terug en meldt dat zowel het mediarecord als de koppeling op goedkeuring wachten. Keur beide wijzigingen in webtrees goed voordat je verder bewerkt.
5. `get-media` toont alleen toegankelijke bestandsnamen en metadata. Wijzigen bewaart weggelaten velden; ontkoppelen raakt alleen de opgegeven koppeling. Verwijderen bewaart het bestand zodat afwijzen van de wijziging of gedeeld gebruik geen afbeelding kwijtraakt.

De clientvoorbeelden zijn documentatie; bestaande clientinstellingen worden niet automatisch aangepast.

## Local verification and review

Use PHP 8.4+ with GD, fileinfo, mbstring and SQLite for tests; production also needs the dependencies/extensions required by Composer. Run `composer install` from the committed lockfile. For isolated tests, unpack a webtrees release outside this repository:

```sh
WEBTREES_TEST_ROOT=/path/to/unpacked/webtrees php tests/media.php
WEBTREES_TEST_ROOT=/path/to/unpacked/webtrees php tests/media-contracts.php
```

The first suite uses real SQLite transactions, PSR-7 and Flysystem with webtrees record/access doubles. The second loads real webtrees classes and checks method availability, discovery, middleware and schema consistency. Neither logs into a live site or exercises OAuth token issuance. A production webtrees database and live Codex/OpenCode sessions remain a separate integration check before deployment. Concurrent media API writes are serialized per tree; unrelated webtrees editor actions do not take that API lock.
## Aanvulling na live-acceptatietest (13 september 2026)

- MCP pending media teruglezen vereist `mcp_read_member`, naast de webtrees-rechten van de technische gebruiker. `mcp_write` en REST-scope `api_read_member` geven geen MCP-member-leesrecht. Met alleen `mcp_read_privacy` kan een nieuwe XREF tot goedkeuring 404 geven: bewaar de XREF en upload niet opnieuw. Laat een beheerder zo nodig een passende token uitgeven.
- 5 MiB is de maximale gedecodeerde afbeelding, geen gegarandeerde transportcapaciteit. MCP accepteert maximaal 8 MiB JSON, verder begrensd door PHP `post_max_size`. Base64 vergroot bestanden met ongeveer een derde. Bij overschrijding geeft de module HTTP 413 met JSON-RPC `error.data.maxBodyBytes`, ook als PHP de body heeft weggegooid. Een proxy/webserver kan eerder afwijzen.
- Als de webserver een body afkapt maar de oorspronkelijke `Content-Length` bewaart, herkent de module dit verschil en geeft hij eveneens 413 met `receivedBodyBytes`; een afgekapt JSON-body eindigt daarmee niet meer als een misleidende JSON-RPC parse error.
- Als een gedeelde host ook de zichtbare lengte aanpast, herkent de module grote JSON-bodies zonder afsluitende `}` of `]` als afgekapt en geeft hij 413. Een opzettelijk ongeldige grote JSON-request kan daardoor dezelfde 413 krijgen; dat voorkomt een misleidende parsefout.
- Voor de volle 5 MiB MCP-upload is minimaal 8M `post_max_size` nodig. Voor 20 MiB REST-upload: `upload_max_filesize` minstens 20M en `post_max_size` groter dan 20M, bijvoorbeeld 24M wegens multipart-overhead. Dit zijn beheerinstructies; de code verandert geen serverconfiguratie.
- Gebruik REST `POST /api/media`, `POST /api/media/links` en `GET /api/media/download`. Download met het volledige relatieve `filename` uit get-media, inclusief mappen, URL-gecodeerd; niet alleen de bestandsnaam.
- Pending wijzigingen blijven beschermd. Verkeerde testuploads en links moeten door een moderator worden afgewezen. Bestandsopruiming is een aparte beheeractie; afwijzen verwijdert niet automatisch het opgeslagen bestand.
