<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EmailVerificationService
{
    public function sendVerificationLink(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }

    public function verify(Request $request): User
    {
        if (! $request->hasValidSignature()) {
            throw new InvalidArgumentException('Invalid or expired verification link.');
        }

        $user = User::query()
            ->withoutGlobalScopes()
            ->findOrFail((int) $request->query('id'));

        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $request->query('hash'))) {
            throw new InvalidArgumentException('Invalid verification hash.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $user;
    }
}
