<?php

use App\Services\Pricing\PricingEngine;

/**
 * The golden vectors only check the invoice-level `expected` fields; this
 * proves the per-line `lines` output SaleService will use to populate
 * sale_items/sale_item_taxes is itself correct, using the same CGST/SGST
 * vector from shared/golden-vectors/tax.json (verified by hand in the
 * phase-4 report): line 1 (150 taxable) -> 13.50 CGST + 13.50 SGST, line 2
 * (100 taxable) -> 9.00 CGST + 9.00 SGST.
 */
test('per-line tax breakdown sums to the invoice-level tax breakdown', function () {
    $engine = new PricingEngine;

    $result = $engine->calculate([
        'lines' => [
            ['unit_price' => '50.0000', 'quantity' => '3.0000', 'line_discount' => ['type' => 'none', 'value' => '0.0000'], 'tax_group' => 'g'],
            ['unit_price' => '20.0000', 'quantity' => '5.0000', 'line_discount' => ['type' => 'none', 'value' => '0.0000'], 'tax_group' => 'g'],
        ],
        'invoice_discount' => ['type' => 'none', 'value' => '0.0000'],
        'tax_groups' => ['g' => ['mode' => 'exclusive', 'components' => [['code' => 'CGST', 'rate' => '9.0000'], ['code' => 'SGST', 'rate' => '9.0000']]]],
        'rounding' => ['mode' => 'half_up', 'cash_step' => '0.0100'],
    ]);

    expect($result['lines'])->toHaveCount(2);
    expect($result['lines'][0]['taxable_amount'])->toBe('150.0000');
    expect($result['lines'][0]['tax_breakdown'])->toBe([
        ['code' => 'CGST', 'amount' => '13.5000'],
        ['code' => 'SGST', 'amount' => '13.5000'],
    ]);
    expect($result['lines'][1]['taxable_amount'])->toBe('100.0000');
    expect($result['lines'][1]['tax_breakdown'])->toBe([
        ['code' => 'CGST', 'amount' => '9.0000'],
        ['code' => 'SGST', 'amount' => '9.0000'],
    ]);

    // Per-line amounts must sum exactly to the invoice-level totals this
    // same vector already proves correct.
    $sumCgst = bcadd($result['lines'][0]['tax_breakdown'][0]['amount'], $result['lines'][1]['tax_breakdown'][0]['amount'], 4);
    expect($sumCgst)->toBe('22.5000');
});
