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
  },
};

let currentLocale = 'en';

export function setLocale(locale) {
  currentLocale = DEV_DICTIONARY[locale] ? locale : 'en';
}

export function t(key) {
  return DEV_DICTIONARY[currentLocale]?.[key] ?? DEV_DICTIONARY.en[key] ?? key;
}
