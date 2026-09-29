# Follow-up reconciliation

`FollowUp` uses the bank's SOAP `FOLLOW_UP` operation only. The bundled WSDL is from the official paymentgateway endpoint (29 September 2026), interpreted alongside Web Service Manual v2.4 pp. 9–11, 20–29, 56, 61–63, the IRIS v1.1 guide and epay's confirmation that the existing redirection credentials may use GR014 for this operation.

For new sale tickets an immutable reference/amount/currency/merchant/POS snapshot is saved before redirecting. Action Scheduler checks after five minutes; unresolved outcomes are checked every fifteen minutes. WP-Cron is the fallback. Hook registration is independent of gateway construction, so cron loads it reliably. Run a server cron for WordPress/Action Scheduler; scheduling depends on workers actually running.

Before retry or automatic unpaid cancellation the bank is checked under the same MySQL advisory lock as receipts and callbacks. A confirmed full success completes a still pending/failed order once. A documented decline or Failure/68 allows reference rotation, or cancellation when the WooCommerce hold interval has elapsed. SOAP failures, 1010, Pending, mismatched data and partial approval do not permit another payment or cancellation. A private review note is added when cancellation is blocked or an unresolved attempt is at least 24 hours old. Such orders retain stock and require merchant reconciliation if uncertainty persists; there is intentionally no invented bank expiration deadline.

Historical orders without a snapshot are not automatically fulfilled. Their confirmed timeout may allow a new attempt; otherwise they require review. Cancelled/refunded/previously paid orders are not revived. Preauthorisation mode retains existing behavior; this addition handles sales only. Manual admin cancellation and third-party status changes are outside the WooCommerce unpaid cancellation filter.

The integer SOAP TransactionID is stored separately as `_piraeusbank_followup_transaction_id`. It must not overwrite a long IRIS identifier. Actual IRIS responses tested on this MID omitted PaymentMethod, IRISTransactionID and IRISStatus. If there is no existing or returned IRIS identifier, WooCommerce transaction_id remains empty and the SOAP ID is in private metadata and the order note. SupportReferenceID is retained separately as well.

No automatic changes to historical records are performed on activation. No credentials or raw SOAP messages are logged. Credentials stay in the gateway settings. The AcquirerID override applies only to FOLLOW_UP.

Offline tests (PHP 8.3, SOAP extension disabled for the mock transport):

```sh
php -n tests/followup-regression.php
php -n -d extension=mbstring tests/payment-regression.php
```

A real read-only check confirmed #1668 Success/00 and #1600/#1673 Failure/68. No new payment was created for testing. A complete sandbox lifecycle including simultaneous external callbacks and cron remains distinct from these offline and read-only production checks.
