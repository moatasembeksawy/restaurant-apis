<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Otp\OtpDeliveryException;
use App\Shared\Infrastructure\Otp\SmsMisrOtpSender;
use App\Shared\Infrastructure\Otp\TwilioOtpSender;
use App\Shared\Infrastructure\Otp\VonageOtpSender;
use App\Shared\Infrastructure\Otp\WhatsAppOtpSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends an OTP through Twilio', function (): void {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

    (new TwilioOtpSender('AC123', 'secret', from: '+15005550006'))->send('+201001234567', '123456', 10, 'en');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
        && $r['To'] === '+201001234567'
        && $r['From'] === '+15005550006'
        && str_contains($r['Body'], '123456'));
});

it('sends an OTP through Vonage', function (): void {
    Http::fake(['rest.nexmo.com/*' => Http::response(['messages' => [['status' => '0']]])]);

    (new VonageOtpSender('key', 'secret', 'Restaurant'))->send('+201001234567', '123456', 10, 'ar');

    Http::assertSent(fn (Request $r): bool => $r['to'] === '201001234567' && str_contains($r['text'], '123456'));
});

it('treats a non-zero Vonage message status as a failure', function (): void {
    Http::fake(['rest.nexmo.com/*' => Http::response(['messages' => [['status' => '4', 'error-text' => 'Bad Credentials']]])]);

    expect(fn () => (new VonageOtpSender('key', 'bad', 'Restaurant'))->send('+201001234567', '123456', 10, 'en'))
        ->toThrow(OtpDeliveryException::class, 'Bad Credentials');
});

it('sends an OTP through SMS Misr', function (): void {
    Http::fake(['smsmisr.com/*' => Http::response(['code' => '1901'])]);

    (new SmsMisrOtpSender('user', 'pass', 'sender-token'))->send('+201001234567', '123456', 10, 'ar');

    Http::assertSent(fn (Request $r): bool => $r['mobile'] === '201001234567' && $r['language'] === 2 && $r['environment'] === 2);
});

it('sends an OTP through a WhatsApp authentication template', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

    (new WhatsAppOtpSender('PNID', 'token', 'verification_code'))->send('+201001234567', '123456', 10, 'ar');

    Http::assertSent(fn (Request $r): bool => $r['to'] === '201001234567'
        && $r['template']['name'] === 'verification_code'
        && $r['template']['language']['code'] === 'ar'
        && $r['template']['components'][1]['parameters'][0]['text'] === '123456');
});

it('refuses to send when a provider is not configured', function (): void {
    Http::fake();

    expect(fn () => (new TwilioOtpSender('', ''))->send('+201001234567', '123456', 10, 'en'))
        ->toThrow(OtpDeliveryException::class);

    Http::assertNothingSent();
});
