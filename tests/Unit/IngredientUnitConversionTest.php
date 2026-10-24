<?php

declare(strict_types=1);

use App\Modules\Inventory\Stock\Models\Ingredient;

it('converts grams to kilograms for stock deduction', function (): void {
    expect(Ingredient::convertQuantity(610, 'g', 'kg'))->toBe(0.61);
    expect(Ingredient::convertQuantity(0.5, 'kg', 'g'))->toBe(500.0);
    expect(Ingredient::convertQuantity(500, 'g', 'g'))->toBe(500.0);
});

it('canonicalizes arabic mass units', function (): void {
    expect(Ingredient::canonicalizeUnit('جم'))->toBe('g');
    expect(Ingredient::canonicalizeUnit('جرام'))->toBe('g');
    expect(Ingredient::canonicalizeUnit('كجم'))->toBe('kg');
    expect(Ingredient::canonicalizeUnit('كيلو'))->toBe('kg');
    expect(Ingredient::convertQuantity(610, 'جم', 'كيلو'))->toBe(0.61);
});

it('rejects converting mass to volume', function (): void {
    Ingredient::convertQuantity(1, 'g', 'ml');
})->throws(InvalidArgumentException::class);
