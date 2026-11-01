/**
 * Minimal translation lookup used until Phase 1 wires GET /tenant/config
 * (which returns the resolved `translations` rows for the user's languages).
 * Never hardcode UI text directly in components (G12) - add a key here
 * (or, from Phase 1 on, in the `translations` table) instead.
 */
const DEV_DICTIONARY = {
  en: {
    'app.boot.title': 'Advanced Universal POS',
    'app.boot.subtitle': 'Loading tenant configuration…',
    'auth.login.title': 'Sign in',
    'auth.login.shop_code': 'Shop code',
    'auth.login.username': 'Username',
    'auth.login.password': 'Password',
    'auth.login.submit': 'Sign in',
    'auth.login.failed': 'Invalid shop code, username, or password.',
    'auth.login.locked': 'This account is temporarily locked. Try again later.',
    'app.menu.empty': 'No menu items are enabled for this account yet.',
    'app.logout': 'Log out',
    'sync.badge.online': 'Online',
    'sync.badge.offline': 'Offline',
    'sync.badge.syncing': 'Syncing…',
    'sync.badge.error': 'Sync error',
    'sync.badge.pending': 'Pending sync',
    'sync.badge.sync_now': 'Sync now',
    'pos.title': 'Billing',
    'pos.branch': 'Branch',
    'pos.register': 'Register',
    'pos.price_list': 'Price list',
    'pos.payment_method': 'Payment method',
    'pos.no_branches': 'No branch or register is set up for this account yet.',
    'pos.search.label': 'Search products',
    'pos.search.placeholder': 'Search by name or SKU…',
    'pos.search.idle': 'Start typing to search products.',
    'pos.search.loading': 'Searching…',
    'pos.search.error': 'Could not search products. Check your connection and try again.',
    'pos.search.empty': 'No products match that search.',
    'pos.search.no_price': 'No price set',
    'pos.search.add': 'Add',
    'pos.shift.loading': 'Checking shift status…',
    'pos.shift.error': 'Could not load shift status.',
    'pos.shift.opening_cash': 'Opening cash',
    'pos.shift.open_button': 'Open shift',
    'pos.shift.open_since': 'Shift open since {{time}}',
    'pos.shift.counted_cash': 'Counted cash',
    'pos.shift.close_button': 'Close shift',
    'pos.shift.closed': 'Shift closed. Cash difference: {{difference}}',
    'pos.customer.label': 'Customer',
    'pos.customer.placeholder': 'Search by name or phone…',
    'pos.customer.selected': 'Customer: {{name}}',
    'pos.customer.clear': 'Remove',
    'pos.cart.empty': 'Cart is empty. Search for a product to add it.',
    'pos.cart.subtotal': 'Subtotal',
    'pos.cart.remove': 'Remove',
    'pos.checkout.button': 'Charge',
    'pos.checkout.success': 'Sale completed. Invoice {{invoice}}.',
    'pos.checkout.queued': 'Saved offline. Will sync automatically when connected.',
    'pos.checkout.rejected': 'This sale was rejected. See the sync status for details.',
    'pos.checkout.shift_required': 'Open a shift before billing.',
  },
};

let currentLocale = 'en';

export function setLocale(locale) {
  currentLocale = DEV_DICTIONARY[locale] ? locale : 'en';
}

/**
 * `params` fills `{{name}}` placeholders in the looked-up string - still
 * entirely dictionary-driven (G12/section 4's Dynamic-Only Rule: no UI text
 * is ever built by concatenating literals in a component).
 */
export function t(key, params) {
  const value = DEV_DICTIONARY[currentLocale]?.[key] ?? DEV_DICTIONARY.en[key] ?? key;
  if (!params) {
    return value;
  }
  return Object.entries(params).reduce(
    (text, [name, replacement]) => text.replaceAll(`{{${name}}}`, String(replacement ?? '')),
    value,
  );
}
