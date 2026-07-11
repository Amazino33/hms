<?php

use App\Services\PackConversionService;

it('converts a purchase-unit quantity to base units using the pack size', function () {
    expect(PackConversionService::toBaseQty(2, 'purchase_unit', 12))->toBe(24.0);
});

it('leaves a base-unit quantity unchanged', function () {
    expect(PackConversionService::toBaseQty(5, 'base_unit', 12))->toBe(5.0);
});

it('throws when converting a purchase-unit quantity without a configured pack size', function () {
    expect(fn () => PackConversionService::toBaseQty(2, 'purchase_unit', null))
        ->toThrow(InvalidArgumentException::class);
});

it('derives unit cost from line total and base quantity', function () {
    expect(PackConversionService::unitCost(12000, 24))->toBe(500.0);
});

it('returns zero unit cost instead of dividing by zero', function () {
    expect(PackConversionService::unitCost(12000, 0))->toBe(0.0);
});
