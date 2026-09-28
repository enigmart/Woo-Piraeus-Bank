# Payment regression tests

Run with PHP 8.3 and mbstring, with the SOAP extension disabled (the suite defines an offline SOAP double):

```sh
php -n -d extension=mbstring tests/payment-regression.php
```

The 55 assertions cover callback verification and duplicate handling, preservation of long IRIS transaction IDs, failure notices, verified IRIS timeout retries, exact reference matching across SOAP/form/ticket storage, stale callbacks, and paid/cancelled order guards. Tests do not connect to WordPress, a database, or the bank. The PHP test entry point rejects non-CLI requests.

After a verified IRIS timeout, the next attempt uses an order reference with an R1/R2 suffix while keeping the original WooCommerce order. Refreshes reuse the active reference. Existing numeric references remain supported.

These tests do not replace an end-to-end bank acceptance test: allow an IRIS attempt to expire, retry, verify a new reference in Paycenter, and complete payment on the original order.

Deployment note: once retry references have been issued, do not restore an older callback handler that only accepts numeric references while those attempts remain outstanding. Plugin updates must retain this callback compatibility.
