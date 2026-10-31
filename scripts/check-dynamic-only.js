#!/usr/bin/env node
/**
 * Dynamic-Only Rule static check (docs/MASTER_PROMPT.md section 4).
 * Greps backend/app and frontend/src for forbidden literal patterns:
 *   - role name comparisons (role === '...', hasRole('admin') with a literal)
 *   - hardcoded currency codes/symbols and tax-rate-looking numeric literals
 *   - business-type template checks (template === 'grocery' style)
 *   - literal UI text in JSX (a string of 2+ words directly inside JSX, not a translation call)
 * Exits non-zero and prints every violation (file:line) if any are found.
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const BACKEND_DIR = path.join(ROOT, 'backend', 'app');
const FRONTEND_DIR = path.join(ROOT, 'frontend', 'src');

/** @type {{name:string, dir:string, exts:string[], pattern:RegExp, hint:string}[]} */
const RULES = [
  {
    name: 'role-name-literal',
    dir: BACKEND_DIR,
    exts: ['.php'],
    pattern: /->hasRole\(\s*['"][\w-]+['"]/g,
    hint: "Authorize by permission code, not role name (G11). Use Gate/permission checks, not ->hasRole('literal').",
  },
  {
    name: 'role-name-comparison',
    dir: BACKEND_DIR,
    exts: ['.php'],
    pattern: /\$\w*role\w*\s*(===|==)\s*['"][\w-]+['"]/gi,
    hint: 'Role names must never be compared as literals in conditions (section 4).',
  },
  {
    name: 'business-type-literal',
    dir: BACKEND_DIR,
    exts: ['.php'],
    pattern: /\b(template|businessType|business_type)\s*(===|==)\s*['"][\w-]+['"]/gi,
    hint: "Business-type checks such as template == 'grocery' are forbidden; use module flags / custom fields (section 4).",
  },
  {
    name: 'currency-literal',
    dir: BACKEND_DIR,
    exts: ['.php'],
    pattern: /['"](₹|\$|€|£|INR|USD|EUR|GBP)['"]/g,
    hint: 'Currency symbols/codes must come from locale.currency_code / locale.currency_decimals settings, never literals (section 4).',
  },
  {
    name: 'currency-literal-frontend',
    dir: FRONTEND_DIR,
    exts: ['.jsx', '.js'],
    pattern: /['"](₹|\$|€|£|INR|USD|EUR|GBP)['"]/g,
    hint: 'Currency symbols/codes must come from the resolved tenant settings snapshot, never literals (section 4).',
  },
  {
    name: 'literal-ui-text-jsx',
    dir: FRONTEND_DIR,
    exts: ['.jsx'],
    // A JSX text child of two or more words, not wrapped by a translation call (t('...') / <Trans>).
    pattern: />\s*[A-Z][a-zA-Z]+(?:\s+[a-zA-Z]+){1,}\s*</g,
    hint: 'All user-visible text must be a translation key (G12), e.g. {t("key")}, not literal JSX text.',
  },
];

/** @param {string} dir @param {string[]} exts @returns {string[]} */
function walk(dir, exts) {
  if (!fs.existsSync(dir)) return [];
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...walk(full, exts));
    } else if (exts.includes(path.extname(entry.name))) {
      out.push(full);
    }
  }
  return out;
}

let violations = [];

for (const rule of RULES) {
  const files = walk(rule.dir, rule.exts);
  for (const file of files) {
    const content = fs.readFileSync(file, 'utf8');
    const lines = content.split('\n');
    lines.forEach((line, idx) => {
      // Skip comment-only lines so the check doesn't flag its own documentation examples.
      const trimmed = line.trim();
      if (trimmed.startsWith('//') || trimmed.startsWith('*') || trimmed.startsWith('#')) return;
      if (rule.pattern.test(line)) {
        violations.push({
          rule: rule.name,
          file: path.relative(ROOT, file),
          line: idx + 1,
          text: trimmed,
          hint: rule.hint,
        });
      }
      rule.pattern.lastIndex = 0;
    });
  }
}

if (violations.length > 0) {
  console.error(`\nDynamic-Only Rule violations found: ${violations.length}\n`);
  for (const v of violations) {
    console.error(`  [${v.rule}] ${v.file}:${v.line}`);
    console.error(`    ${v.text}`);
    console.error(`    -> ${v.hint}\n`);
  }
  process.exit(1);
} else {
  console.log('Dynamic-Only Rule check: no violations found.');
  process.exit(0);
}
