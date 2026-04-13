<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use Symfony\Component\HttpFoundation\Request;

/**
 * Provides safe JSON body parsing for API controllers.
 *
 * Returns the decoded array on success, or null when the body is absent,
 * not valid JSON, or not a JSON object (a scalar/array at root level).
 * Callers should respond with HTTP 422 on null.
 */
trait JsonRequestTrait
{
    private function parseJson(Request $request): ?array
    {
        $content = $request->getContent();

        if ($content === '' || $content === null) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
