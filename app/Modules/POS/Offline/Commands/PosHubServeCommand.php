<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Commands;

use Illuminate\Console\Command;

class PosHubServeCommand extends Command
{
    protected $signature = 'pos:hub {--host=} {--port=}';

    protected $description = 'Serve the LAN POS hub so kitchen and POS tablets can share orders without internet';

    public function handle(): int
    {
        if (! config('pos-hub.enabled')) {
            $this->error('Set POS_HUB_ENABLED=true on this branch mini-PC before starting the hub.');

            return self::FAILURE;
        }

        if ((string) config('pos-hub.token') === '') {
            $this->error('Set POS_HUB_TOKEN from POST /offline/hubs. The plaintext token is shown only once.');

            return self::FAILURE;
        }

        $host = (string) ($this->option('host') ?: config('pos-hub.host'));
        $port = (string) ($this->option('port') ?: config('pos-hub.port'));
        $discovery = (string) config('pos-hub.discovery_host');

        $this->info("POS hub listening on http://{$host}:{$port}");
        $this->comment("Kitchen/POS discovery: http://{$discovery}:{$port}/api/v1/hub/health");
        $this->comment('Send X-Hub-Token on every hub route except /health.');

        return $this->call('serve', [
            '--host' => $host,
            '--port' => $port,
        ]);
    }
}
