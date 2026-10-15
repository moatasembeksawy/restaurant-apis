<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Commands;

use App\Modules\POS\Offline\Services\HubCloudSyncService;
use Illuminate\Console\Command;
use RuntimeException;

class PosHubSyncCommand extends Command
{
    protected $signature = 'pos:hub:sync';

    protected $description = 'Upload pending LAN hub operations to the cloud offline sync endpoint';

    public function handle(HubCloudSyncService $sync): int
    {
        if (! config('pos-hub.enabled')) {
            $this->error('Set POS_HUB_ENABLED=true on this branch mini-PC before syncing.');

            return self::FAILURE;
        }

        try {
            $result = $sync->sync();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Hub cloud sync — uploaded: %d, still pending: %d',
            $result['synced'],
            $result['pending'],
        ));

        return self::SUCCESS;
    }
}
