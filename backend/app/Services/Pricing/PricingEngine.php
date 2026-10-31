<?php

namespace App\Services\Pricing;

/**
 * section 9 "One pricing engine, tested twice": a pure function, no DB/
 * network access, operating entirely on bcmath decimal strings (G3 - never
 * a native float). This class and frontend/src/pricing/pricingEngine.js
 * MUST agree on every vector in shared/golden-vectors/*.json - that's
 * enforced by running the identical files through both test suites
 * (backend: PricingEngineGoldenVectorTest, frontend: pricingEngine.test.js).
 *
 * Input/output shape is documented in shared/golden-vectors/README.md.
 * The server's result (this class) is authoritative (section 9); the JS
 * copy is only ever a client-side preview.
 */
class PricingEngine
{
    private const SCALE = 4;

    public function calculate(array $input): array
    {
        $taxGroups = $input['tax_groups'];
        $rounding = $input['rounding'];

        $lineSubtotal = '0.0000';
        $lineDiscountTotal = '0.0000';
        $lines = [];

        foreach ($input['lines'] as $line) {
            $gross = bcmul($line['unit_price'], $line['quantity'], self::SCALE);
            $lineDiscount = $this->discountAmount($line['line_discount'] ?? ['type' => 'none', 'value' => '0.0000'], $gross);
            $net = bcsub($gross, $lineDiscount, self::SCALE);

            $lineSubtotal = bcadd($lineSubtotal, $gross, self::SCALE);
            $lineDiscountTotal = bcadd($lineDiscountTotal, $lineDiscount, self::SCALE);

            $lines[] = ['net' => $net, 'line_discount' => $lineDiscount, 'tax_group' => $taxGroups[$line['tax_group']]];
        }

        $sumNet = array_reduce($lines, fn ($carry, $l) => bcadd($carry, $l['net'], self::SCALE), '0.0000');

        $invoiceDiscount = $this->discountAmount($input['invoice_discount'] ?? ['type' => 'none', 'value' => '0.0000'], $sumNet);
        $discountTotal = bcadd($lineDiscountTotal, $invoiceDiscount, self::SCALE);

        $taxableAmount = '0.0000';
        $taxTotal = '0.0000';
        $taxBreakdown = []; // code => amount, insertion-ordered
        $lineResults = []; // per-line detail, consumed by SaleService for sale_items/sale_item_taxes - not part of any golden vector's `expected`

        foreach ($lines as $index => $line) {
            // Prorate the invoice-level discount across lines by each
            // line's own share of the (already line-discounted) net total,
            // so a sale with multiple tax groups still taxes each line's
            // remaining share at that line's own rate (section 7
            // "price lists" / docs/sync-design.md pricing notes).
            $share = bccomp($sumNet, '0.0000', self::SCALE) > 0
                ? bcdiv($line['net'], $sumNet, 10)
                : '0.0000000000';
            $lineInvoiceDiscount = bcmul($invoiceDiscount, $share, self::SCALE);
            $lineNetAfterAllDiscounts = bcsub($line['net'], $lineInvoiceDiscount, self::SCALE);

            $taxGroup = $line['tax_group'];
            $totalRate = array_reduce($taxGroup['components'], fn ($c, $comp) => bcadd($c, $comp['rate'], 10), '0.0000000000');

            if ($taxGroup['mode'] === 'inclusive') {
                $divisor = bcadd('1', bcdiv($totalRate, '100', 10), 10);
                $lineTaxable = bcdiv($lineNetAfterAllDiscounts, $divisor, self::SCALE);
                $lineTax = bcsub($lineNetAfterAllDiscounts, $lineTaxable, self::SCALE);
            } else {
                $lineTaxable = $lineNetAfterAllDiscounts;
                $lineTax = bcdiv(bcmul($lineTaxable, $totalRate, 10), '100', self::SCALE);
            }

            $taxableAmount = bcadd($taxableAmount, $lineTaxable, self::SCALE);
            $taxTotal = bcadd($taxTotal, $lineTax, self::SCALE);

            $lineTaxBreakdown = [];
            foreach ($taxGroup['components'] as $component) {
                $componentShare = bccomp($totalRate, '0.0000000000', 10) > 0
                    ? bcdiv($component['rate'], $totalRate, 10)
                    : '0.0000000000';
                $componentAmount = bcmul($lineTax, $componentShare, self::SCALE);
                $taxBreakdown[$component['code']] = bcadd($taxBreakdown[$component['code']] ?? '0.0000', $componentAmount, self::SCALE);
                $lineTaxBreakdown[] = ['code' => $component['code'], 'amount' => $componentAmount];
            }

            $lineResults[$index] = [
                'line_discount' => $line['line_discount'],
                'invoice_discount_share' => $lineInvoiceDiscount,
                'taxable_amount' => $lineTaxable,
                'tax_amount' => $lineTax,
                'tax_breakdown' => $lineTaxBreakdown,
                'line_total' => bcadd($lineTaxable, $lineTax, self::SCALE),
            ];
        }

        $preRoundingTotal = bcadd($taxableAmount, $taxTotal, self::SCALE);
        $grandTotal = $this->roundToStep($preRoundingTotal, $rounding['cash_step'], $rounding['mode']);
        $roundingAdjustment = bcsub($grandTotal, $preRoundingTotal, self::SCALE);

        return [
            'line_subtotal' => $lineSubtotal,
            'discount_total' => $discountTotal,
            'taxable_amount' => $taxableAmount,
            'tax_breakdown' => array_map(fn ($code, $amount) => ['code' => $code, 'amount' => $amount], array_keys($taxBreakdown), array_values($taxBreakdown)),
            'tax_total' => $taxTotal,
            'pre_rounding_total' => $preRoundingTotal,
            'grand_total' => $grandTotal,
            'rounding_adjustment' => $roundingAdjustment,
            'lines' => $lineResults,
        ];
    }

    private function discountAmount(array $discount, string $base): string
    {
        return match ($discount['type']) {
            'percent' => bcdiv(bcmul($base, $discount['value'], 10), '100', self::SCALE),
            'amount' => $discount['value'],
            default => '0.0000',
        };
    }

    /**
     * Rounds `amount` to the nearest multiple of `cashStep`, using bcmath
     * throughout (G3) so there is never a float in the path - PHP's native
     * round() operates on floats and would risk exactly the imprecision
     * G3 forbids.
     */
    private function roundToStep(string $amount, string $cashStep, string $mode): string
    {
        $isNegative = bccomp($amount, '0.0000', self::SCALE) < 0;
        $absAmount = $isNegative ? bcmul($amount, '-1', self::SCALE) : $amount;

        $quotient = bcdiv($absAmount, $cashStep, 10);
        $intPart = bcdiv($quotient, '1', 0);
        $fracPart = bcsub($quotient, $intPart, 10);

        $cmp = bccomp($fracPart, '0.5', 10);
        if ($cmp < 0) {
            $roundedQuotient = $intPart;
        } elseif ($cmp > 0) {
            $roundedQuotient = bcadd($intPart, '1', 0);
        } else {
            if ($mode === 'half_even') {
                $isEven = bcmod($intPart, '2') === '0';
                $roundedQuotient = $isEven ? $intPart : bcadd($intPart, '1', 0);
            } else {
                $roundedQuotient = bcadd($intPart, '1', 0);
            }
        }

        $result = bcmul($roundedQuotient, $cashStep, self::SCALE);

        return $isNegative ? bcmul($result, '-1', self::SCALE) : $result;
    }
}
