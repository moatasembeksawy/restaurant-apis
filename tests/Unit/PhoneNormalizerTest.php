<?php

declare(strict_types=1);

use App\Shared\Support\Phone\PhoneNormalizer;

it('normalizes Egyptian mobiles to E.164', function (string $input): void {
    expect(PhoneNormalizer::normalize($input))->toBe('+201001234567');
})->with([
    '01001234567',
    '+201001234567',
    '201001234567',
    '00201001234567',
    '1001234567',
    '010 0123-4567',
    '(010) 0123 4567',
]);

it('normalizes local landlines and foreign numbers', function (string $input, string $expected): void {
    expect(PhoneNormalizer::normalize($input))->toBe($expected);
})->with([
    ['0222345678', '+20222345678'],
    ['+20222345678', '+20222345678'],
    ['+447911123456', '+447911123456'],
    ['00447911123456', '+447911123456'],
]);

it('returns null for blank input', function (?string $input): void {
    expect(PhoneNormalizer::normalize($input))->toBeNull();
})->with([null, '', '   ']);

it('validates phones', function (string $input, bool $any, bool $mobile): void {
    expect(PhoneNormalizer::isValid($input))->toBe($any)
        ->and(PhoneNormalizer::isValid($input, mobileOnly: true))->toBe($mobile);
})->with([
    'egyptian mobile' => ['01001234567', true, true],
    'egyptian landline' => ['0222345678', true, false],
    'unknown egyptian mobile prefix' => ['01301234567', false, false],
    'too short' => ['0100123', false, false],
    'foreign' => ['+447911123456', true, false],
    'letters' => ['not-a-phone', false, false],
]);
