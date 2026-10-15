<?php

declare(strict_types=1);

use App\Modules\POS\Offline\Commands\PosHubSyncCommand;
use App\Modules\Tenant\Subscription\Commands\ProcessSubscriptionsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(ProcessSubscriptionsCommand::class)->daily();

Schedule::command(PosHubSyncCommand::class)
    ->everyMinute()
    ->when(fn (): bool => (bool) config('pos-hub.enabled'));

Schedule::command('telescope:prune --hours='.(int) config('telescope.prune_hours', 48))->daily();
