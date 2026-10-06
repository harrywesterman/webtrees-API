<?php

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\WebtreesApi\Helpers;

final class StructuredContent
{
    /**
     * Return the payload as structuredContent only when it is a JSON object.
     * MCP clients validate structuredContent against the tool's outputSchema, so
     * scalars, lists and plain text are left to the text content. This avoids
     * failures such as "must have required property 'xref'".
     */
    public static function objectOrNull(string $content): ?string
    {
        if (!json_validate($content)) {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) && !array_is_list($decoded) ? $content : null;
    }
}
