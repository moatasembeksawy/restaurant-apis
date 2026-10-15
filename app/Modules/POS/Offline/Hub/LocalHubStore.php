<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Hub;

use App\Modules\POS\Offline\Support\OfflineOperations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LocalHubStore
{
    public function __construct(
        private readonly string $path,
    ) {
        File::ensureDirectoryExists($this->path);
    }

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public function publish(string $deviceId, string $role, array $operations): array
    {
        return $this->locked(function () use ($deviceId, $role, $operations): array {
            $state = $this->readState();
            $published = [];

            foreach ($operations as $operation) {
                $event = $this->appendUnlocked($state, $deviceId, $role, $operation);
                $this->project($state, $event);
                $published[] = $event;
            }

            $this->writeState($state);

            return $published;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsAfter(int $afterId = 0): array
    {
        $events = [];
        $file = $this->eventsPath();

        if (! is_file($file)) {
            return [];
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $event = json_decode(trim($line), true);
                if (is_array($event) && (int) ($event['id'] ?? 0) > $afterId) {
                    $events[] = $event;
                }
            }
        } finally {
            fclose($handle);
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    public function kitchenQueue(): array
    {
        $state = $this->readState();
        $queue = [];

        foreach ($state['orders'] as $order) {
            if (! in_array($order['status'], ['active', 'cooking'], true)) {
                continue;
            }

            $items = array_values(array_filter(
                $order['items'],
                fn (array $item): bool => in_array($item['status'], ['pending', 'cooking'], true),
            ));

            if ($items === []) {
                continue;
            }

            $queue[] = array_merge($order, ['items' => $items]);
        }

        usort($queue, fn (array $a, array $b): int => $a['local_number'] <=> $b['local_number']);

        return $queue;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function saveSnapshot(array $snapshot): void
    {
        $this->locked(function () use ($snapshot): void {
            $state = $this->readState();
            $state['snapshot'] = $snapshot;
            $state['snapshot_saved_at'] = now()->toISOString();
            $this->writeState($state);
        });
    }

    /** @return array<string, mixed>|null */
    public function snapshot(): ?array
    {
        return $this->readState()['snapshot'];
    }

    /** @return list<array<string, mixed>> */
    public function pendingCloudOperations(): array
    {
        $state = $this->readState();
        $synced = $state['cloud_synced'] ?? [];
        $pending = [];

        foreach ($this->eventsAfter(0) as $event) {
            $operationId = (string) ($event['operation_id'] ?? '');
            if ($operationId !== '' && ! in_array($operationId, $synced, true)) {
                $pending[] = [
                    'operation_id' => $operationId,
                    'type' => $event['type'],
                    'occurred_at' => $event['occurred_at'],
                    'payload' => $event['payload'],
                ];
            }
        }

        return $pending;
    }

    /** @param  list<string>  $operationIds */
    public function markCloudSynced(array $operationIds): void
    {
        $this->locked(function () use ($operationIds): void {
            $state = $this->readState();
            $state['cloud_synced'] = array_values(array_unique([
                ...$state['cloud_synced'],
                ...$operationIds,
            ]));
            $this->writeState($state);
        });
    }

    public function deviceId(): string
    {
        return $this->locked(function (): string {
            $state = $this->readState();
            if (empty($state['device_id'])) {
                $state['device_id'] = (string) Str::uuid();
                $this->writeState($state);
            }

            return (string) $state['device_id'];
        });
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $state = $this->readState();

        return [
            'ok' => true,
            'branch_id' => config('pos-hub.branch_id'),
            'event_count' => max(0, $state['next_event_id'] - 1),
            'open_tickets' => count($this->kitchenQueue()),
            'port' => (int) config('pos-hub.port'),
            'discovery_host' => config('pos-hub.discovery_host'),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        File::ensureDirectoryExists($this->path);
        $lockPath = $this->path.DIRECTORY_SEPARATOR.'hub.lock';
        $handle = fopen($lockPath, 'c+');

        if ($handle === false) {
            throw new InvalidArgumentException('Unable to lock the POS hub store.');
        }

        try {
            flock($handle, LOCK_EX);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function appendUnlocked(array &$state, string $deviceId, string $role, array $operation): array
    {
        $event = [
            'id' => $state['next_event_id'],
            'operation_id' => (string) $operation['operation_id'],
            'device_id' => $deviceId,
            'role' => $role,
            'type' => (string) $operation['type'],
            'occurred_at' => (string) $operation['occurred_at'],
            'payload' => $operation['payload'],
            'created_at' => now()->toISOString(),
        ];

        foreach ($this->eventsAfter(0) as $existing) {
            if (($existing['operation_id'] ?? null) === $event['operation_id']) {
                return array_merge($existing, ['duplicate' => true]);
            }
        }

        $state['next_event_id']++;
        file_put_contents(
            $this->eventsPath(),
            json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            FILE_APPEND | LOCK_EX,
        );

        return $event;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $event
     */
    private function project(array &$state, array $event): void
    {
        if (! empty($event['duplicate'])) {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $event['payload'];
        $clientOrderId = (string) ($payload['client_order_id'] ?? '');

        match ((string) $event['type']) {
            OfflineOperations::ORDER_CREATED => $this->projectCreated($state, $payload, $clientOrderId),
            OfflineOperations::ITEM_ADDED => $this->mutateItem($state, $clientOrderId, $payload, 'add'),
            OfflineOperations::ITEM_UPDATED => $this->mutateItem($state, $clientOrderId, $payload, 'update'),
            OfflineOperations::ITEM_REMOVED => $this->mutateItem($state, $clientOrderId, $payload, 'remove'),
            OfflineOperations::STATUS_CHANGED => $this->projectStatus($state, $clientOrderId, (string) $payload['status']),
            OfflineOperations::ITEM_READY => $this->projectItemReady($state, $clientOrderId, (string) $payload['client_item_id']),
            OfflineOperations::PAYMENT_RECORDED => $this->projectStatus($state, $clientOrderId, 'paid'),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     */
    private function projectCreated(array &$state, array $payload, string $clientOrderId): void
    {
        if (isset($state['orders'][$clientOrderId])) {
            return;
        }

        $items = [];
        foreach ($payload['items'] ?? [] as $index => $item) {
            $clientItemId = (string) ($item['client_item_id'] ?? $clientOrderId.'-'.$index);
            $items[$clientItemId] = $this->itemFromPayload($item, $clientItemId, $state);
        }

        $state['orders'][$clientOrderId] = [
            'client_order_id' => $clientOrderId,
            'local_number' => $state['next_local_number']++,
            'channel' => $payload['channel'] ?? 'dine_in',
            'fulfillment_type' => $payload['fulfillment_type'] ?? 'dine_in',
            'floor_table_id' => $payload['floor_table_id'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'status' => 'active',
            'items' => array_values($items),
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     */
    private function mutateItem(array &$state, string $clientOrderId, array $payload, string $action): void
    {
        if (! isset($state['orders'][$clientOrderId])) {
            throw new InvalidArgumentException('Order not found on the LAN hub.');
        }

        $order = &$state['orders'][$clientOrderId];

        $clientItemId = (string) $payload['client_item_id'];

        if ($action === 'add') {
            foreach ($order['items'] as $item) {
                if ($item['client_item_id'] === $clientItemId) {
                    return;
                }
            }
            $order['items'][] = $this->itemFromPayload($payload, $clientItemId, $state);
        }

        if ($action === 'update') {
            foreach ($order['items'] as &$item) {
                if ($item['client_item_id'] === $clientItemId) {
                    $item['quantity'] = (int) $payload['quantity'];
                    if (array_key_exists('notes', $payload)) {
                        $item['notes'] = $payload['notes'];
                    }
                }
            }
            unset($item);
        }

        if ($action === 'remove') {
            $order['items'] = array_values(array_filter(
                $order['items'],
                fn (array $item): bool => $item['client_item_id'] !== $clientItemId,
            ));
        }

        $order['updated_at'] = now()->toISOString();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function projectStatus(array &$state, string $clientOrderId, string $status): void
    {
        if (! isset($state['orders'][$clientOrderId])) {
            throw new InvalidArgumentException('Order not found on the LAN hub.');
        }

        $state['orders'][$clientOrderId]['status'] = $status;
        $state['orders'][$clientOrderId]['updated_at'] = now()->toISOString();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function projectItemReady(array &$state, string $clientOrderId, string $clientItemId): void
    {
        if (! isset($state['orders'][$clientOrderId])) {
            throw new InvalidArgumentException('Order not found on the LAN hub.');
        }

        $allReady = true;
        foreach ($state['orders'][$clientOrderId]['items'] as &$item) {
            if ($item['client_item_id'] === $clientItemId) {
                $item['status'] = 'ready';
                $item['cooked_at'] = now()->toISOString();
            }
            if (! in_array($item['status'], ['ready', 'served', 'cancelled'], true)) {
                $allReady = false;
            }
        }
        unset($item);

        $state['orders'][$clientOrderId]['status'] = $allReady ? 'ready' : 'cooking';
        $state['orders'][$clientOrderId]['updated_at'] = now()->toISOString();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function itemFromPayload(array $payload, string $clientItemId, array $state): array
    {
        $menuItemId = isset($payload['menu_item_id']) ? (int) $payload['menu_item_id'] : null;
        $name = $payload['item_name_ar'] ?? $this->menuName($state, $menuItemId);

        return [
            'client_item_id' => $clientItemId,
            'menu_item_id' => $menuItemId,
            'package_id' => $payload['package_id'] ?? null,
            'name_ar' => $name,
            'quantity' => (int) ($payload['quantity'] ?? 1),
            'notes' => $payload['notes'] ?? null,
            'unit_price' => isset($payload['unit_price']) ? (float) $payload['unit_price'] : null,
            'status' => 'pending',
            'cooked_at' => null,
        ];
    }

    /** @param  array<string, mixed>  $state */
    private function menuName(array $state, ?int $menuItemId): string
    {
        if ($menuItemId === null) {
            return 'صنف';
        }

        foreach ($state['snapshot']['menu_items'] ?? [] as $item) {
            if ((int) ($item['id'] ?? 0) === $menuItemId) {
                return (string) ($item['name_ar'] ?? 'صنف');
            }
        }

        return 'صنف '.$menuItemId;
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        $file = $this->statePath();
        if (! is_file($file)) {
            return $this->defaultState();
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? array_merge($this->defaultState(), $decoded) : $this->defaultState();
    }

    /** @return array<string, mixed> */
    private function defaultState(): array
    {
        return [
            'next_event_id' => 1,
            'next_local_number' => 1,
            'device_id' => null,
            'orders' => [],
            'snapshot' => null,
            'snapshot_saved_at' => null,
            'cloud_synced' => [],
        ];
    }

    /** @param  array<string, mixed>  $state */
    private function writeState(array $state): void
    {
        file_put_contents(
            $this->statePath(),
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }

    private function statePath(): string
    {
        return $this->path.DIRECTORY_SEPARATOR.'state.json';
    }

    private function eventsPath(): string
    {
        return $this->path.DIRECTORY_SEPARATOR.'events.jsonl';
    }
}
