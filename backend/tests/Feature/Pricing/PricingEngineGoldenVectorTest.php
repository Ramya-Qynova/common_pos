<?php

use App\Services\Pricing\PricingEngine;

/**
 * section 9 "one pricing engine, tested twice": loads every vector in
 * shared/golden-vectors/*.json and asserts this PHP engine's output
 * matches EXACTLY (string-for-string on every DECIMAL(18,4) field) -
 * the same files frontend/src/pricing/pricingEngine.test.js runs through
 * the JS engine. A vector the two engines disagree on must fail here or
 * there.
 */
function goldenVectorFiles(): array
{
    // Resolved via __DIR__, not base_path() - this runs at Pest's file
    // collection time, before the Laravel app is bootstrapped.
    $dir = __DIR__.'/../../../../shared/golden-vectors';

    return glob($dir.'/*.json');
}

$engine = new PricingEngine;

foreach (goldenVectorFiles() as $file) {
    $vectors = json_decode(file_get_contents($file), true);

    foreach ($vectors as $vector) {
        test("golden vector [{$vector['id']}] (".basename($file).')', function () use ($engine, $vector) {
            $result = $engine->calculate($vector['input']);

            foreach ($vector['expected'] as $field => $expectedValue) {
                if ($field === 'tax_breakdown') {
                    expect($result['tax_breakdown'])->toBe($expectedValue);
                } else {
                    expect($result[$field])->toBe($expectedValue);
                }
            }
        });
    }
}
