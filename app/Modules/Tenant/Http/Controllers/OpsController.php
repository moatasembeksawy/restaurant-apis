<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Shared\Support\Http\Resources\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * @group Operations
 */
class OpsController extends Controller
{
    public function health(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $queueConnection = (string) config('queue.default', 'redis');
        $queues = ['default', 'eta', 'whatsapp', 'reports', 'notifications'];
        $queueDepths = [];

        if ($queueConnection === 'redis') {
            foreach ($queues as $queue) {
                try {
                    $queueDepths[$queue] = (int) Redis::connection()->llen("queues:{$queue}");
                } catch (\Throwable) {
                    $queueDepths[$queue] = null;
                }
            }
        }

        $horizonStatus = $this->horizonStatus();

        return ApiResponse::success([
            'app' => [
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
            ],
            'database' => [
                'connected' => $this->databaseConnected(),
            ],
            'redis' => [
                'connected' => $this->redisConnected(),
            ],
            'queue' => [
                'connection' => $queueConnection,
                'depths' => $queueDepths,
            ],
            'horizon' => $horizonStatus,
            'reverb' => [
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme', 'http'),
            ],
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    private function authorizeOwner(Request $request): void
    {
        if ($request->user()->role !== 'owner') {
            throw new HttpResponseException(
                ApiResponse::error('Only the owner can view operational health.', 'FORBIDDEN', 403),
            );
        }
    }

    private function databaseConnected(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function redisConnected(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{available: bool, status: string} */
    private function horizonStatus(): array
    {
        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            return ['available' => false, 'status' => 'not_installed'];
        }

        try {
            $masters = Redis::connection(config('horizon.use', 'default'))
                ->smembers('horizon:masters');

            return [
                'available' => true,
                'status' => $masters !== [] ? 'running' : 'stopped',
            ];
        } catch (\Throwable) {
            return ['available' => true, 'status' => 'unknown'];
        }
    }
}
