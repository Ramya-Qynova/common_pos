# Phase 14: Two-Factor Authentication (2FA / MFA / TOTP & Recovery Codes)

## 1. Objectives & Scope
Phase 14 implements the fourth core capability of Release 2 as outlined in Section 2 & 8 of the architecture specification:
- **Two-Factor Authentication Core Engine**:
  - RFC 6238 TOTP (Time-Based One-Time Password) implementation with HMAC-SHA1 dynamic binary code truncation and RFC 4648 Base32 secret decoding.
  - Time window drift tolerance (+/- 1 step / 30 seconds) preventing clock skew failures.
  - Single-use hashed backup recovery codes (8 codes per user, consumed on use and never retrievable as plaintext).
  - Cryptographically secure challenge tokens (SHA-256 hashed in database, 5-minute expiry) to prevent pre-auth token leakage.
- **Login Flow Integration (Section 8)**:
  - If a user has 2FA enabled, standard password verification returns `{ two_factor_required: true, two_factor_token: ... }`.
  - Client submits TOTP code or recovery code to `POST /api/v1/auth/2fa/verify` to complete authentication and receive the authoritative access and rotating refresh tokens.
  - Security events logged: `two_factor_challenged`, `login_success_2fa`, `two_factor_recovery_code_used`, `two_factor_failed`, `two_factor_enabled`, `two_factor_disabled`.
- **User Profile 2FA Lifecycle**:
  - `POST /api/v1/auth/2fa/setup`: Generates base32 secret, standard `otpauth://totp/...` QR URI, and 8 recovery codes.
  - `POST /api/v1/auth/2fa/confirm`: Verifies initial code before permanently enabling 2FA.
  - `POST /api/v1/auth/2fa/disable`: Disables 2FA with current password confirmation.
- **Frontend Operational UI**:
  - `LoginScreen.jsx`: Seamless reactive transition to 2FA challenge form upon receiving `two_factor_required`.
  - `TwoFactorModal.jsx`: Self-service 2FA activation, manual secret copy, recovery code vault, and disable controls.
  - `AuthContext.jsx`: `verifyTwoFactor` pipeline.
  - Dynamic-Only Rule (G12): 100% dictionary-driven translation keys via `i18n`.

---

## 2. Models & Components Created / Enhanced
- `backend/database/migrations/2026_11_02_000001_add_two_factor_to_users_table.php`: Added 2FA columns to `users` and created `two_factor_challenges` table.
- `backend/app/Models/User.php`: Added 2FA fillable, hidden secrets, and datetime casts.
- `backend/app/Models/TwoFactorChallenge.php`: Challenge token model with expiration and tenant scoping.
- `backend/app/Services/Auth/TwoFactorAuthService.php`: Core RFC 6238 TOTP engine, secret generation, recovery code hashing, and challenge verification.
- `backend/app/Services/Auth/TenantAuthService.php`: Integrated 2FA challenge generation on login and `completeTwoFactorLogin`.
- `backend/app/Http/Controllers/Api/V1/AuthController.php`: Added `verifyTwoFactor`, `setupTwoFactor`, `confirmTwoFactor`, and `disableTwoFactor`.
- `frontend/src/screens/LoginScreen.jsx`: Interactive 2FA verification challenge screen.
- `frontend/src/screens/TwoFactorModal.jsx`: Profile 2FA settings modal.
- `backend/tests/Feature/Auth/TwoFactorAuthTest.php`: Comprehensive Pest feature test suite.

---

## 3. Endpoints Added & Tested
- `POST /api/v1/auth/2fa/verify`: Submit 6-digit TOTP code or recovery code against challenge token.
- `POST /api/v1/auth/2fa/setup`: Initiate 2FA enrollment.
- `POST /api/v1/auth/2fa/confirm`: Confirm enrollment with test code.
- `POST /api/v1/auth/2fa/disable`: Deactivate 2FA with password confirmation.

---

## 4. Test Verification & Quality Gates
- **Backend Pest Suite**: **156 passed (776 assertions)** across 100% of test files.
- **Backend Pint Formatter**: 0 issues, fully PSR-12 compliant.
- **Frontend Vitest Suite**: **11 test suites, 46 passed (100%)**.
- **Frontend ESLint**: 0 errors.
- **Frontend Vite Build**: Production bundle created successfully (`dist/`).
- **Dynamic-Only Static Check**: 0 violations found.

