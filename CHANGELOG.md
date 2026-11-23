# Changelog

All notable changes to this package are listed here. Versions follow [Semantic Versioning](https://semver.org).

## [1.0.1] - 2026-11-23

### Fixed (verified on Paymob staging)
- The client reference id is now also sent as `reference`: Paymob's inquiry by reference searches that field, so timed-out payouts are found.
- Read our reference back from Paymob's `client_transaction_reference` field.

### Added
- `DisbursementResult::fees()` and `::vat()`.

## [1.0.0] - 2026-11-23

### Added
- `PaymobPayouts` client: token login, caching, refresh and automatic renewal on 401.
- Payouts to Vodafone, Etisalat and Orange wallets, bank wallets, bank cards, instant bank transfers and Egypt Post.
- Validation before sending (issuer fields, Egyptian mobile numbers, national id, instant-bank minimum).
- Results with `successful` / `failed` / `pending` / `unknown`; timeouts and server errors are `unknown`, never `failed`.
- Transaction inquiry by Paymob id or client reference, and balance inquiry.
- Token stores: in-memory, file, Laravel cache.
- Laravel: service provider, config, facade, `paymob_payouts` migration, `PayoutManager` (one payout per reference, record-before-send, manual settlement), events, `paymob-payouts:reconcile` and `paymob-payouts:balance` commands.
