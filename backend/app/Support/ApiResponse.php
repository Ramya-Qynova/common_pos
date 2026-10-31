<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The one standard response envelope (docs/MASTER_PROMPT.md section 5
 * "Standard response"). Every controller returns through this, never a
 * bare array/model.
 */
class ApiResponse
{
    public static function success(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function error(string $code, string $message, int $status, array $fields = [], array $details = [], ?string $messageKey = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($messageKey) {
            $error['message_key'] = $messageKey;
        }
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['success' => false, 'error' => $error], $status);
    }
}
