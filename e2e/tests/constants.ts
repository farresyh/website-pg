// "localhost", not "127.0.0.1" — deliberately matches config/cors.php's
// ADMIN_URL/STOREFRONT_URL defaults exactly (found the hard way: the
// Origin header is the literal hostname in the URL, not a resolved IP,
// so 127.0.0.1 silently fails Laravel's CORS check against localhost).
export const BACKEND_URL = "http://localhost:8003";
export const ADMIN_URL = "http://localhost:3000";
export const STOREFRONT_URL = "http://localhost:3001";

/** Seeded by backend/database/seeders/E2ESeeder.php — see ADR-023 decision #7. */
export const E2E_ADMIN_EMAIL = "test@example.com";
export const E2E_ADMIN_PASSWORD = "password";
export const E2E_GAME_SLUG = "e2e-test-game";
export const E2E_VOUCHER_FIXTURE_ORDER_NUMBER = "PG-E2E-VOUCHER-FIXTURE";
export const E2E_RESEND_FIXTURE_ORDER_NUMBER = "PG-E2E-RESEND-FIXTURE";
export const E2E_NEEDS_REVIEW_FIXTURE_ORDER_NUMBER = "PG-E2E-NEEDSREVIEW-FIXTURE";

/** ADR-068 — pre-verifiable OTP fixture for the membership-subscribe golden path (E2ESeeder). */
export const E2E_MEMBER_EMAIL = "e2e-member@example.com";
export const E2E_MEMBER_OTP = "123456";
