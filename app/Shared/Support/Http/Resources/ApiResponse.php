<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $payload = ['data' => $data];

        if ($data instanceof LengthAwarePaginator) {
            $payload = [
                'data' => $data->items(),
                'meta' => array_merge([
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                    'message' => $message,
                ], $meta),
            ];
        } else {
            $payload['meta'] = array_merge(['message' => $message], $meta);
        }

        return response()->json($payload, $status);
    }

    public static function created(mixed $data = null, string $message = 'Created.'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    public static function error(
        string $message,
        string $code = 'ERROR',
        int $status = 400,
        array $errors = [],
    ): JsonResponse {
        return response()->json([
            'errors' => array_merge(
                [['message' => $message, 'code' => $code]],
                $errors,
            ),
        ], $status);
    }

    public static function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'errors' => collect($errors)->map(fn ($messages, $field) => [
                'message' => $messages[0],
                'code' => 'VALIDATION_ERROR',
                'field' => $field,
            ])->values()->all(),
        ], 422);
    }
}
