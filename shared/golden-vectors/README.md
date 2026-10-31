# Golden vectors

Pure-function pricing/tax/rounding test cases that **both** the PHP pricing engine (`backend`, authoritative) and the JS pricing engine (`frontend`, offline preview) must evaluate identically (docs/MASTER_PROMPT.md section 9, "one pricing engine, tested twice"). Implemented against in Phase 4.

## Schema

Each `*.json` file is an array of vectors:

```jsonc
{
  "id": "unique-kebab-case-id",
  "description": "human-readable scenario",
  "input": {
    "lines": [
      {
        "unit_price": "100.0000",      // DECIMAL(18,4) as a string - G3
        "quantity": "2.0000",
        "line_discount": { "type": "none" | "percent" | "amount", "value": "0.0000" },
        "tax_group": "<key into input.tax_groups>"
      }
    ],
    "invoice_discount": { "type": "none" | "percent" | "amount", "value": "0.0000" },
    "tax_groups": {
      "<key>": {
        "mode": "inclusive" | "exclusive",
        "components": [{ "code": "CGST", "rate": "9.0000" }]
      }
    },
    "rounding": { "mode": "half_up" | "half_even", "cash_step": "1.0000" }
  },
  "expected": {
    "line_subtotal": "200.0000",
    "discount_total": "0.0000",
    "taxable_amount": "200.0000",
    "tax_breakdown": [{ "code": "CGST", "amount": "18.0000" }],
    "tax_total": "36.0000",
    "pre_rounding_total": "236.0000",
    "grand_total": "236.0000",
    "rounding_adjustment": "0.0000"
  }
}
```

A test runner in both suites loads every file here, feeds `input` to its pricing engine, and asserts the result equals `expected` exactly (string-for-string on `DECIMAL(18,4)` fields). A vector added here that the two engines disagree on must fail the build (docs/MASTER_PROMPT.md section 9 and 13.1).

## Files

- `tax.json` - inclusive vs exclusive mode, single and multi-component (CGST+SGST) GST.
- `discounts.json` - line-level and invoice-level percent/amount discounts, combined.
- `rounding.json` - cash rounding to a step, half-up vs half-even on a borderline value.
