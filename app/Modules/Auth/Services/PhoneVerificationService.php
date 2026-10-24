<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Exceptions\PhoneVerificationException;
use App\Modules\Auth\Models\PhoneVerificationCode;
use App\Shared\Infrastructure\Otp\OtpDeliveryException;
use App\Shared\Infrastructure\Otp\OtpSenderInterface;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Phone\PhoneNormalizer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PhoneVerificationService
{
    public function __construct(private readonly OtpSenderInterface $sender) {}

    public function isEnabled(): bool
    {
        return (bool) config('phone_verification.enabled', false);
    }

    /**
     * Send a code to the user's own phone. Returns false when it is already verified.
     *
     * @throws PhoneVerificationException
     */
    public function sendVerificationCode(User $user): bool
    {
        $this->ensureEnabled();

        if ($user->phone === null) {
            throw PhoneVerificationException::missingPhone();
        }

        if ($user->hasVerifiedPhone()) {
            return false;
        }

        $this->issue($user, PhoneVerificationCode::PURPOSE_VERIFY_PHONE);

        return true;
    }

    /** @throws PhoneVerificationException */
    public function verify(User $user, string $code): void
    {
        $this->ensureEnabled();

        if ($user->phone === null) {
            throw PhoneVerificationException::missingPhone();
        }

        if ($user->hasVerifiedPhone()) {
            return;
        }

        $this->consume($user, PhoneVerificationCode::PURPOSE_VERIFY_PHONE, $code);

        $user->markPhoneAsVerified();

        AuditLogger::log('auth.phone_verified', $user);
    }

    /**
     * Always succeeds silently for unknown phones so the endpoint can't be used to find accounts.
     *
     * @throws PhoneVerificationException
     */
    public function sendPasswordResetCode(string $phone): void
    {
        $this->ensureEnabled();

        $user = $this->findResettableUser($phone);

        if (! $user) {
            return;
        }

        try {
            $this->issue($user, PhoneVerificationCode::PURPOSE_PASSWORD_RESET);
        } catch (PhoneVerificationException $e) {
            Log::warning('Phone password reset code not sent.', ['user_id' => $user->id, 'reason' => $e->errorCode]);
        }
    }

    /** @throws PhoneVerificationException */
    public function resetPassword(string $phone, string $code, string $password): void
    {
        $this->ensureEnabled();

        $user = $this->findResettableUser($phone);

        if (! $user) {
            throw PhoneVerificationException::invalidCode();
        }

        $this->consume($user, PhoneVerificationCode::PURPOSE_PASSWORD_RESET, $code);

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        $user->tokens()->delete();

        event(new PasswordReset($user));

        AuditLogger::log('auth.password_reset', $user, ['method' => 'phone']);
    }

    private function ensureEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw PhoneVerificationException::disabled();
        }
    }

    /** Only verified phones may reset passwords; an unverified number may not belong to the account holder. */
    private function findResettableUser(string $phone): ?User
    {
        $normalized = PhoneNormalizer::normalize($phone);

        if ($normalized === null) {
            return null;
        }

        return User::query()
            ->withoutGlobalScopes()
            ->where('phone', $normalized)
            ->whereNotNull('phone_verified_at')
            ->whereNotNull('password')
            ->where('is_active', true)
            ->first();
    }

    private function issue(User $user, string $purpose): void
    {
        $cooldown = (int) config('phone_verification.resend_cooldown_seconds', 60);

        $latest = PhoneVerificationCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();

        if ($latest && $latest->created_at->gt(now()->subSeconds($cooldown))) {
            throw PhoneVerificationException::cooldown(
                max(1, (int) ceil(now()->diffInSeconds($latest->created_at->copy()->addSeconds($cooldown)))),
            );
        }

        $phone = (string) $user->phone;
        $code = $this->generateCode();
        $ttl = (int) config('phone_verification.ttl_minutes', 10);

        $record = DB::transaction(function () use ($user, $purpose, $phone, $code, $ttl): PhoneVerificationCode {
            PhoneVerificationCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return PhoneVerificationCode::create([
                'user_id' => $user->id,
                'phone' => $phone,
                'purpose' => $purpose,
                'channel' => $this->sender->channel(),
                'code_hash' => $this->hash($user, $phone, $purpose, $code),
                'expires_at' => now()->addMinutes($ttl),
            ]);
        });

        try {
            $this->sender->send($phone, $code, $ttl, app()->getLocale());
        } catch (OtpDeliveryException $e) {
            $record->delete();
            Log::error('Phone verification code delivery failed.', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            throw PhoneVerificationException::deliveryFailed();
        }

        AuditLogger::log('auth.phone_code_sent', $user, ['purpose' => $purpose, 'channel' => $this->sender->channel()]);
    }

    private function consume(User $user, string $purpose, string $code): void
    {
        $maxAttempts = (int) config('phone_verification.max_attempts', 5);

        // Throwing inside the transaction would roll back the attempt counter, so return the outcome instead.
        $outcome = DB::transaction(function () use ($user, $purpose, $code, $maxAttempts): string {
            $record = PhoneVerificationCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->where('phone', $user->phone)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $record || $record->isExpired()) {
                return 'invalid';
            }

            if ($record->attempts >= $maxAttempts) {
                return 'locked';
            }

            $record->increment('attempts');

            if (! hash_equals($record->code_hash, $this->hash($user, $record->phone, $purpose, $code))) {
                if ($record->attempts >= $maxAttempts) {
                    $record->update(['consumed_at' => now()]);

                    return 'locked';
                }

                return 'invalid';
            }

            $record->update(['consumed_at' => now()]);

            return 'ok';
        });

        match ($outcome) {
            'ok' => null,
            'locked' => throw PhoneVerificationException::tooManyAttempts(),
            default => throw PhoneVerificationException::invalidCode(),
        };
    }

    private function generateCode(): string
    {
        $length = max(4, min(10, (int) config('phone_verification.code_length', 6)));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    /** Bound to user, phone and purpose so a code can't be replayed for another account or flow. */
    private function hash(User $user, string $phone, string $purpose, string $code): string
    {
        return hash_hmac('sha256', implode('|', [$user->id, $phone, $purpose, $code]), (string) config('app.key'));
    }
}
