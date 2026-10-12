<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        $recordAll = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($recordAll): bool {
            return $recordAll
                || $entry->isReportableException()
                || $entry->isFailedRequest()
                || $entry->isFailedJob()
                || $entry->isScheduledTask()
                || $entry->hasMonitoredTag()
                || $entry->isLog();
        });
    }

    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'secret',
            'pin',
            'otp',
        ]);

        Telescope::hideRequestHeaders([
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);

        Telescope::hideResponseParameters([
            'token',
            'access_token',
            'refresh_token',
            'password',
        ]);
    }

    protected function authorization(): void
    {
        Telescope::auth(fn (Request $request): bool => self::allows($request));
    }

    public static function allows(Request $request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        $username = (string) config('telescope.basic_auth.username');
        $password = (string) config('telescope.basic_auth.password');

        if ($username === '' || $password === '') {
            return false;
        }

        return hash_equals($username, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword());
    }
}
