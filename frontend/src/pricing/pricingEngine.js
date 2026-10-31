import Decimal from 'decimal.js';

/**
 * section 9 "one pricing engine, tested twice": client-side mirror of
 * backend/app/Services/Pricing/PricingEngine.php. MUST produce the exact
 * same output (string-for-string on every money field) for every vector
 * in shared/golden-vectors/*.json - enforced by pricingEngine.test.js,
 * which runs the identical files through this module. This is a preview
 * only (G1) - the server result is always authoritative.
 */
const SCALE = 4;

function toDecimal(value) {
  return new Decimal(value);
}

function fmt(decimal) {
  return decimal.toFixed(SCALE);
}

function discountAmount(discount, base) {
  if (!discount || discount.type === 'none') return new Decimal(0);
  if (discount.type === 'percent') {
    return toDecimal(base).mul(discount.value).div(100);
  }
  return toDecimal(discount.value);
}

function roundToStep(amount, cashStep, mode) {
  const isNegative = amount.isNegative();
  const absAmount = amount.abs();

  const quotient = absAmount.div(cashStep);
  const intPart = quotient.trunc();
  const fracPart = quotient.minus(intPart);

  let roundedQuotient;
  const cmp = fracPart.comparedTo('0.5');
  if (cmp < 0) {
    roundedQuotient = intPart;
  } else if (cmp > 0) {
    roundedQuotient = intPart.plus(1);
  } else if (mode === 'half_even') {
    const isEven = intPart.mod(2).isZero();
    roundedQuotient = isEven ? intPart : intPart.plus(1);
  } else {
    roundedQuotient = intPart.plus(1);
  }

  const result = roundedQuotient.mul(cashStep);

  return isNegative ? result.negated() : result;
}

export function calculate(input) {
  const { tax_groups: taxGroups, rounding } = input;

  let lineSubtotal = new Decimal(0);
  let lineDiscountTotal = new Decimal(0);
  const lines = [];

  for (const line of input.lines) {
    const gross = toDecimal(line.unit_price).mul(line.quantity).toDecimalPlaces(SCALE);
    const lineDiscount = discountAmount(line.line_discount, gross).toDecimalPlaces(SCALE);
    const net = gross.minus(lineDiscount).toDecimalPlaces(SCALE);

    lineSubtotal = lineSubtotal.plus(gross).toDecimalPlaces(SCALE);
    lineDiscountTotal = lineDiscountTotal.plus(lineDiscount).toDecimalPlaces(SCALE);

    lines.push({ net, lineDiscount, taxGroup: taxGroups[line.tax_group] });
  }

  const sumNet = lines.reduce(
    (carry, l) => carry.plus(l.net).toDecimalPlaces(SCALE),
    new Decimal(0),
  );

  const invoiceDiscount = discountAmount(input.invoice_discount, sumNet).toDecimalPlaces(SCALE);
  const discountTotal = lineDiscountTotal.plus(invoiceDiscount).toDecimalPlaces(SCALE);

  let taxableAmount = new Decimal(0);
  let taxTotal = new Decimal(0);
  const taxBreakdownOrder = [];
  const taxBreakdownMap = {};
  const lineResults = []; // per-line detail, mirrors backend's `lines` output - not checked by any golden vector's `expected`

  for (const line of lines) {
    // Share/rate ratios stay at 10 decimal places (matching the PHP
    // engine's bcdiv(..., 10) calls) - only amounts are rounded to SCALE.
    const share = sumNet.isPositive() ? line.net.div(sumNet).toDecimalPlaces(10) : new Decimal(0);
    const lineInvoiceDiscount = invoiceDiscount.mul(share).toDecimalPlaces(SCALE);
    const lineNetAfterAllDiscounts = line.net.minus(lineInvoiceDiscount).toDecimalPlaces(SCALE);

    const taxGroup = line.taxGroup;
    const totalRate = taxGroup.components
      .reduce((c, comp) => c.plus(comp.rate), new Decimal(0))
      .toDecimalPlaces(10);

    // Every intermediate is rounded to SCALE immediately, matching the PHP
    // engine's bcmath calls (bcdiv/bcmul/bcsub are always given a scale) -
    // not just at the end of the loop. Decimal.js otherwise keeps full
    // precision internally, which would silently diverge from PHP on any
    // input where that extra precision actually matters.
    let lineTaxable;
    let lineTax;
    if (taxGroup.mode === 'inclusive') {
      const divisor = new Decimal(1).plus(totalRate.div(100)).toDecimalPlaces(10);
      lineTaxable = lineNetAfterAllDiscounts.div(divisor).toDecimalPlaces(SCALE);
      lineTax = lineNetAfterAllDiscounts.minus(lineTaxable).toDecimalPlaces(SCALE);
    } else {
      lineTaxable = lineNetAfterAllDiscounts.toDecimalPlaces(SCALE);
      lineTax = lineTaxable.mul(totalRate).div(100).toDecimalPlaces(SCALE);
    }

    taxableAmount = taxableAmount.plus(lineTaxable).toDecimalPlaces(SCALE);
    taxTotal = taxTotal.plus(lineTax).toDecimalPlaces(SCALE);

    const lineTaxBreakdown = [];
    for (const component of taxGroup.components) {
      const componentShare = totalRate.isPositive()
        ? new Decimal(component.rate).div(totalRate).toDecimalPlaces(10)
        : new Decimal(0);
      const componentAmount = lineTax.mul(componentShare).toDecimalPlaces(SCALE);
      if (!(component.code in taxBreakdownMap)) {
        taxBreakdownOrder.push(component.code);
        taxBreakdownMap[component.code] = new Decimal(0);
      }
      taxBreakdownMap[component.code] = taxBreakdownMap[component.code]
        .plus(componentAmount)
        .toDecimalPlaces(SCALE);
      lineTaxBreakdown.push({ code: component.code, amount: fmt(componentAmount) });
    }

    lineResults.push({
      line_discount: fmt(line.lineDiscount),
      invoice_discount_share: fmt(lineInvoiceDiscount),
      taxable_amount: fmt(lineTaxable),
      tax_amount: fmt(lineTax),
      tax_breakdown: lineTaxBreakdown,
      line_total: fmt(lineTaxable.plus(lineTax)),
    });
  }

  const preRoundingTotal = taxableAmount.plus(taxTotal);
  const grandTotal = roundToStep(preRoundingTotal, rounding.cash_step, rounding.mode);
  const roundingAdjustment = grandTotal.minus(preRoundingTotal);

  return {
    line_subtotal: fmt(lineSubtotal),
    discount_total: fmt(discountTotal),
    taxable_amount: fmt(taxableAmount),
    tax_breakdown: taxBreakdownOrder.map((code) => ({ code, amount: fmt(taxBreakdownMap[code]) })),
    tax_total: fmt(taxTotal),
    pre_rounding_total: fmt(preRoundingTotal),
    grand_total: fmt(grandTotal),
    rounding_adjustment: fmt(roundingAdjustment),
    lines: lineResults,
  };
}
