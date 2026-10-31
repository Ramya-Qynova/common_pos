import { readFileSync, readdirSync } from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';
import { calculate } from './pricingEngine';

// section 9 "one pricing engine, tested twice": runs the exact same files
// backend/tests/Feature/Pricing/PricingEngineGoldenVectorTest.php runs
// through the PHP engine - a vector the two engines disagree on must fail
// here or there.
const vectorsDir = path.resolve(__dirname, '../../../shared/golden-vectors');
const vectorFiles = readdirSync(vectorsDir).filter((f) => f.endsWith('.json'));

for (const file of vectorFiles) {
  const vectors = JSON.parse(readFileSync(path.join(vectorsDir, file), 'utf8'));

  describe(`golden vectors (${file})`, () => {
    for (const vector of vectors) {
      it(vector.id, () => {
        const result = calculate(vector.input);

        for (const [field, expected] of Object.entries(vector.expected)) {
          expect(result[field]).toEqual(expected);
        }
      });
    }
  });
}
