<?php

namespace App\Services\Catalogue;

use App\Models\Barcode;
use App\Models\Tenant;
use App\Services\Settings\SettingsService;

/**
 * section 9 "Adapter interfaces": BarcodeScanner. A `fixed` barcode is an
 * exact lookup - works for every tenant regardless of template. A
 * `variable_weight` barcode (a weighing scale's own printed label, prefix +
 * product code + an embedded weight that is different every time the same
 * product is weighed) is decoded using the tenant's `pos.
 * variable_weight_barcode` setting - never a hardcoded format, and only
 * attempted when the `weighing` module is enabled (section 4 Dynamic-Only
 * Rule: a module flag, never a `template === 'grocery'` check, gates this).
 */
class VariableWeightBarcodeService
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @return array{product_id:int,variant_id:?int,unit_id:?int,quantity:?string,barcode_type:string}|null
     */
    public function resolve(int $tenantId, string $scannedCode): ?array
    {
        $exact = Barcode::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('barcode', $scannedCode)->where('type', 'fixed')->first();

        if ($exact) {
            return [
                'product_id' => $exact->product_id, 'variant_id' => $exact->variant_id, 'unit_id' => $exact->unit_id,
                'quantity' => null, 'barcode_type' => 'fixed',
            ];
        }

        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if (! $tenant->isModuleEnabled('weighing')) {
            return null;
        }

        $format = $this->settings->get('pos.variable_weight_barcode', $tenantId);
        if (! ($format['enabled'] ?? false)) {
            return null;
        }

        $decoded = $this->decode($scannedCode, $format);
        if ($decoded === null) {
            return null;
        }

        $barcode = Barcode::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('barcode', $decoded['fixed_prefix'])->where('type', 'variable_weight')->first();

        if (! $barcode) {
            return null;
        }

        return [
            'product_id' => $barcode->product_id, 'variant_id' => $barcode->variant_id, 'unit_id' => $barcode->unit_id,
            'quantity' => $decoded['quantity'], 'barcode_type' => 'variable_weight',
        ];
    }

    /**
     * @return array{fixed_prefix:string,quantity:string}|null
     */
    private function decode(string $scannedCode, array $format): ?array
    {
        if (strlen($scannedCode) !== (int) $format['total_length']) {
            return null;
        }

        $prefix = (string) $format['prefix'];
        if (substr($scannedCode, 0, strlen($prefix)) !== $prefix) {
            return null;
        }

        $fixedLength = (int) $format['product_code_start'] + (int) $format['product_code_length'];
        $fixedPrefix = substr($scannedCode, 0, $fixedLength);
        $valueDigits = substr($scannedCode, (int) $format['value_start'], (int) $format['value_length']);

        if (! ctype_digit($valueDigits)) {
            return null;
        }

        $divisor = (string) (10 ** (int) $format['value_decimal_places']);
        $quantity = bcdiv((string) ((int) $valueDigits), $divisor, 4);

        return ['fixed_prefix' => $fixedPrefix, 'quantity' => $quantity];
    }
}
