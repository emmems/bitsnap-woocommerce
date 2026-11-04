# Architecture

Bitsnap for WooCommerce is a redirect payment gateway. WooCommerce keeps the catalogue, cart, prices, coupons, taxes, shipping, stock and order status. Bitsnap keeps the payment attempt, the choice of payment method, the buyer's payment page, the payment status and running refunds.

```
WooCommerce checkout (classic or Blocks)
  └─ Gateway::process_payment(order)
       POST /api/v1/bitsnap.v1.CheckoutService/CreateCheckoutSession ──► Bitsnap creates an order
       redirect buyer to the returned url ────────────────────────────► hosted payment page
  buyer returns to the order-received page
       ReturnPage: if still pending, OrdersService/GetOrder once
  Bitsnap POSTs events to {site}/?wc-api=bitsnap
       Webhook\Handler: verify signature, deduplicate, apply
  Reconciler (Action Scheduler, every 15 min): GetOrder for orders pending > 15 min
  Admin refunds an order
       Gateway::process_refund → RefundsService/CreateRefund
```

The Bitsnap order is a payment record. It carries `source = woocommerce`, so Bitsnap does not run its own fulfilment on it.

## Code layout

| Path | Role |
| --- | --- |
| `bitsnap-for-woocommerce.php` | Header, constants, HPOS and Blocks declarations, bootstrap |
| `src/Autoloader.php` | PSR-4 loader. The plugin has **no Composer runtime dependencies** |
| `src/Plugin.php` | Hooks and a small service container |
| `src/Credentials.php`, `src/OAuth/` | Credential provider and OAuth registration, PKCE and token lifecycle |
| `src/Woo/` | Code that needs WooCommerce: `Gateway`, `OrderReader`, `OrderUpdater`, `PaymentStarter`, `OrderSync`, `ReturnPage`, `Reconciler`, `Refunder` |
| `src/Mapping/`, `src/Webhook/{Signature,Event,EventPlanner}`, `src/Support/{Money,Redactor,Secrets,IdempotencyKey}`, `src/Api/ErrorMapper` | **Pure** classes with no WordPress calls, covered by unit tests |
| `src/Api/Client.php` | Thin `wp_remote_post` wrapper for Connect JSON |
| `src/Webhook/Handler.php` | The `wc-api=bitsnap` endpoint |
| `src/Blocks/PaymentMethod.php`, `assets/js/blocks/index.js` | Cart and Checkout Blocks integration |

The rule that keeps the code testable: `Woo\OrderReader` is the only class that reads `WC_Order` line items. It produces a plain array. `Mapping\CheckoutSessionBuilder` turns that array into the request. `Webhook\EventPlanner` turns an event into a list of actions as data, and `Woo\OrderUpdater` applies them.

## The contract with Bitsnap

This is what the plugin relies on. The source of truth is section 9 of the checkout plugin plan in the Bitsnap repository. Calls use Connect JSON: `POST {base}/api/v1/bitsnap.v1.<Service>/<Method>` with `Content-Type: application/json` and `Authorization: Bearer <key>`. Field names are lowerCamelCase, enums are their proto names, `Money` is `{ "amount": <int minor units>, "currency": "PLN" }`. `{base}` defaults to `https://bitsnap.pl` (Advanced settings).

### Credentials

OAuth `bsat_` access and refresh tokens take precedence per environment; otherwise the plugin uses manual API keys and webhook secrets. OAuth secrets use `Support\Secrets` encryption. Access tokens refresh within 60 seconds of expiry and after a 401, with a short transient lock.

### `CheckoutService.CreateCheckoutSession`

Sent with `Idempotency-Key: woo-<site hash>-<order id>-<attempt>`. The attempt number goes up only when the request changed or the old session expired, so a double click replays the same session.

```jsonc
{
  "lines": [{ "name": "T-shirt M", "unitPrice": {"amount": 4900, "currency": "PLN"}, "quantity": 2,
              "vatRate": 23, "sku": "TS-M", "imageUrl": "https://…" }],
  "shipping": { "name": "InPost", "price": {"amount": 1399, "currency": "PLN"}, "vatRate": 23 },
  "discount": {"amount": 500, "currency": "PLN"},
  "total":    {"amount": 10699, "currency": "PLN"},
  "buyer": { "email": "", "firstName": "", "lastName": "", "phone": "", "company": "", "nip": "",
             "billingAddress": {"line1": "", "line2": "", "city": "", "postalCode": "", "country": "PL"},
             "shippingAddress": {…} },
  "successUrl": "https://shop/checkout/order-received/123/?key=wc_order_x",
  "cancelUrl": "https://shop/checkout/order-pay/123/?pay_for_order=true&key=wc_order_x&bitsnap_cancelled=1",
  "clientReferenceId": "123",
  "metadata": { "source": "woocommerce", "wcOrderKey": "wc_order_x", "siteUrl": "https://shop" },
  "locale": "pl"
}
```

Response: `{ url, orderId, environment, expiresAt }`. Errors carry `ErrorInfo.reason`: `invalid_request`, `total_mismatch`, `unsupported_currency`, `missing_scope`, `test_mode_only`, `rate_limited`.

How the plugin fills the request:

- **Lines** use the gross unit price before coupons (`subtotal + subtotal_tax`, divided by the quantity, rounded). The VAT rate is the WooCommerce tax rate applied to the line, or the ratio of tax to net when several rates apply. It is omitted when the shop has taxes switched off.
- **Fees** become lines. A negative fee is added to `discount`.
- **Coupons** become `discount`: the difference between line subtotals and line totals, gross.
- **Shipping** lines are combined into one `shipping` object. Free shipping omits it.
- **Rounding.** `total` is WooCommerce's order total. A unit price is a whole number of grosze, so `3 × 33.33` cannot be exact, and WooCommerce rounds taxes its own way. The plugin sums the lines, then closes any difference: a higher shop total adds a "Rounding" line, a lower one is added to `discount`. Sum of lines + shipping − discount always equals `total`. Lines past the 100-line limit are folded into one "Other items" line.
- **NIP** is read from the order meta of common invoice plugins (`_billing_nip`, `billing_nip`, `_billing_vat_number` and others), validated (ten digits and checksum), and filterable with `bitsnap_wc_order_nip`. The plugin adds no checkout field of its own.
- If the key has no `customers:pii` scope, the plugin retries once without `buyer`.

### `OrdersService.GetOrder` by reference

`{ "clientReferenceId": "123" }` returns the newest order of the credential's environment with that reference. The plugin reads these fields of `order`: `id`, `clientReferenceId`, `metadata`, `paymentGateway`, `paymentId`, `paymentState`, `amountPaid`, `amountRefunded`. `paymentState` is one of `PENDING`, `AWAITING_OFFLINE`, `PAID`, `FAILED`, `EXPIRED`, `REFUNDED`, `PARTIALLY_REFUNDED` (with or without the `PAYMENT_STATE_` prefix). The plugin checks that `clientReferenceId` and `metadata.wcOrderKey` match the WooCommerce order before it acts.

### `RefundsService.CreateRefund`

`{ "orderId", "amount": Money, "reason" }` with `Idempotency-Key: woo-refund-<WooCommerce refund id>`. Response `{ refund: { id, orderId, amount, status, createdAt } }`, status `PENDING`, `SUCCEEDED`, `FAILED` or `MANUAL_REQUIRED`. `MANUAL_REQUIRED` (cash on delivery, manual transfer) and the errors `refund_not_supported`, `amount_exceeds_refundable` and `order_not_paid` become a `WP_Error` with a clear message, so WooCommerce does not record the refund. The Bitsnap refund id is stored in order meta right away so the later `REFUND_SUCCEEDED` webhook does not add a second WooCommerce refund.

### Webhooks

`POST` with a JSON body and `X-Content-Signature` = hex HMAC-SHA512 of the raw body with the `whsec_` secret. Payload: `{ projectId, environment?, eventData: { id, event, order, customer, refund? } }`.

| Event | Action |
| --- | --- |
| `TRANSACTION_CREATED` with `paymentState=AWAITING_OFFLINE` | gateway `cod`: order `processing` with a note. Anything else (manual transfer): `on-hold` |
| `TRANSACTION_SUCCESS` | `payment_complete(paymentId)`. If the order was waiting for COD, only records the payment |
| `TRANSACTION_FAILURE` | `failed`, unless already paid |
| `TRANSACTION_EXPIRED` | `cancelled`, unless already paid |
| `REFUND_SUCCEEDED` | `wc_create_refund` without restock, unless the refund id is already recorded |
| `REFUND_FAILED` | order note |

Handler rules:

1. Verify the signature with `hash_equals` against both the test and the live secret. The secret that matches decides the environment. If one secret is used for both, the payload's `environment` decides.
2. Ignore, with `200`, events that are not for this shop: no `metadata.source = woocommerce`, or a `metadata.siteUrl` that is another site. A Bitsnap project sends every event of every order to every webhook, and two shops can share a project.
3. Find the order by `clientReferenceId` and cross-check `metadata.wcOrderKey` and the stored environment.
4. Skip an event id already in the order's `_bitsnap_events` list (last 50). The id is stored only after the event was applied, so a crash lets the retry run.
5. Apply the planned actions. Every action is safe to run twice.

Response codes:

| Code | Meaning |
| --- | --- |
| `200 ok` / `duplicate` / `ignored` | Done, nothing more to do |
| `400 invalid_signature` / `invalid_payload` / `not_configured` | Rejected. Retrying the same delivery will not help |
| `404 unknown_order` | No matching order here. We chose a definitive `404` over `200` so a delivery for an order that does not exist is visible in the Bitsnap delivery log, and over `5xx` so Bitsnap does not retry it forever |
| `405` | Not a `POST` |
| `500 error` | Unexpected failure while applying. Bitsnap should retry |

### Credentials provider (seam for OAuth)

`Credentials` is the only class that knows where API credentials come from. OAuth fills the same API-token and webhook-secret fields as manual mode. OAuth connection records are scoped to environment and the active API base URL. Client registrations are cached per site, base URL and environment.

## Order meta

All keys are read and written through `WC_Order` meta methods, so the plugin works with HPOS. Keys start with `_bitsnap_`: `order_id`, `environment`, `attempt`, `request_fingerprint`, `expires_at`, `events`, `refund_ids`, `offline`, `payment_gateway`, `return_checked`, `reconciled_at`, `gave_up`.

## Filters

| Filter | Purpose |
| --- | --- |
| `bitsnap_wc_supported_currencies` | Currencies the gateway is offered for (default `PLN`, `EUR`, `USD`, `GBP`) |
| `bitsnap_wc_order_nip` | Provide the NIP from a custom field |
| `bitsnap_wc_order_snapshot` | Change the order snapshot before it is mapped |
| `bitsnap_wc_checkout_request` | Change the final request (the total must still add up) |
| `bitsnap_wc_item_vat_rate` | Change the VAT rate sent for an item |

## Known limits

- Two concurrent deliveries of the same event are not locked against each other. Every action checks the order state first, so the outcome is the same, but a refund event delivered twice at the same instant could in theory record the refund twice.
- Only two-decimal currencies are supported.
- `process_refund()` finds the WooCommerce refund it is processing as the newest refund of the order. WooCommerce creates that record before it calls the gateway, and does not pass its id.
