<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Support;

final class OfflineOperations
{
    public const SCHEMA_VERSION = 3;

    public const ORDER_CREATED = 'order.created';

    public const ITEM_ADDED = 'order.item_added';

    public const ITEM_UPDATED = 'order.item_updated';

    public const ITEM_REMOVED = 'order.item_removed';

    public const STATUS_CHANGED = 'order.status_changed';

    public const ITEM_READY = 'order.item_ready';

    public const PAYMENT_RECORDED = 'payment.recorded';

    /** @var list<string> */
    public const ALL = [
        self::ORDER_CREATED,
        self::ITEM_ADDED,
        self::ITEM_UPDATED,
        self::ITEM_REMOVED,
        self::STATUS_CHANGED,
        self::ITEM_READY,
        self::PAYMENT_RECORDED,
    ];
}
