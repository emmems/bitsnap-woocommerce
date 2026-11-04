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
| `src/Credentials.php` | The one class that knows where API credentials come from: OAuth token first, then the manual key |
| `src/OAuth/` | "Connect with Bitsnap". `Service` (registration, PKCE, state, refresh, revoke) and `Protocol` are **pure**. `Storage`, `TokenStore` and `Transport` are the seams; `TransientStorage`, `SettingsTokenStore` and `WpTransport` are the WordPress side. `ApiTokens` gives `Api\Client` its token |
| `src/Webhook/Registrar.php` | Creates and deletes the plugin's webhook in Bitsnap, and remembers its id (`Store`, `SettingsStore`) |
| `src/Woo/` | Code that needs WooCommerce: `Gateway`, `OrderReader`, `OrderUpdater`, `PaymentStarter`, `OrderSync`, `ReturnPage`, `Reconciler`, `Refunder` |
| `src/Mapping/`, `src/Webhook/{Signature,Event,EventPlanner,Registrar}`, `src/OAuth/{Protocol,Service}`, `src/Support/{Money,Redactor,Secrets,IdempotencyKey}`, `src/Api/ErrorMapper` | **Pure** classes with no WordPress calls, covered by unit tests |
| `src/Api/Client.php` | Thin `wp_remote_post` wrapper for Connect JSON. With OAuth it asks its `TokenSource` for the token and repeats a call once after a 401 |
| `src/Webhook/Handler.php` | The `wc-api=bitsnap` endpoint |
| `src/Blocks/PaymentMethod.php`, `assets/js/blocks/index.js` | Cart and Checkout Blocks integration |

The rule that keeps the code testable: `Woo\OrderReader` is the only class that reads `WC_Order` line items. It produces a plain array. `Mapping\CheckoutSessionBuilder` turns that array into the request. `Webhook\EventPlanner` turns an event into a list of actions as data, and `Woo\OrderUpdater` applies them.

## The contract with Bitsnap

This is what the plugin relies on. The source of truth is section 9 of the checkout plugin plan in the Bitsnap repository. Calls use Connect JSON: `POST {base}/api/v1/bitsnap.v1.<Service>/<Method>` with `Content-Type: application/json` and `Authorization: Bearer <key>`. Field names are lowerCamelCase, enums are their proto names, `Money` is `{ "amount": <int minor units>, "currency": "PLN" }`. `{base}` defaults to `https://bitsnap.pl` (Advanced settings).

### Credentials

Each environment (test, live) has its own credentials, and they are independent. An environment is in one of two modes:

- **OAuth** (`Connect with Bitsnap`): a `bsat_` access token and a rotating refresh token.
- **Manual**: an API key (`bs_test_…`, `bs_live_…`) pasted by the merchant.

OAuth takes precedence over a manual key, per environment. Both modes use the same webhook secret field (`webhook_secret_<env>`) and the same `Api\Client`. `Credentials` is the only place that decides which credential is in use.

### Connect with Bitsnap (OAuth, section 9.6 of the plan)

The flow, all through `OAuth\Service`, which does not call WordPress:

1. **Register.** `POST {base}/oauth/register` (RFC 7591), once per site, base URL and callback. The client id is stored in `oauth_client_id` with a fingerprint of the base URL and the callback, so a new base URL or site address registers again. Both must be `https`, except `http://localhost` and `http://127.0.0.1` for development. Otherwise the connection is refused with a clear message.
2. **Authorize.** `OAuthActions::connect()` (nonce and `manage_woocommerce`) sends the merchant to `{base}/oauth/authorize` with PKCE S256 (`scope=woocommerce offline_access`, `resource={base}/api/v1`, `environment=test|live`). The state is 32 random bytes, kept in a transient under its hash for 10 minutes together with the user id, the environment and the code verifier. The verifier never leaves the site.
3. **Callback.** `admin-post.php?action=bitsnap_oauth_callback` needs `manage_woocommerce`. The state is the CSRF protection (a WordPress nonce cannot ride on a redirect from another site). It is read and deleted first, so it works **once**, then checked against the user and the clock. Only after that is an `error` parameter looked at, and its text is never shown. Input is sanitized and length-limited; the redirect goes to the settings page.
4. **Token.** `POST {base}/oauth/token` (form) with the code, verifier, redirect URI, client id and `resource`. The answer must carry `bitsnap_project_id`, `bitsnap_project_name` and `bitsnap_environment`. **Tokens are stored for `bitsnap_environment`**, the environment the server issued them for, even if the merchant changed it on the consent page.
5. **Webhook.** Right after connecting, `Webhook\Registrar::register(..., replace: true)` deletes this site's earlier webhook of that environment by id and creates a new one, so the stored secret always matches the webhook that exists. A failure here leaves the connection in place and shows a warning.

Tokens are stored encrypted (`Support\Secrets`, libsodium) in the gateway option, like manual keys. The project id and name, expiry and client id are plain. A token pair is written with a single `update_option`, so a rotated pair is never half saved.

**Refreshing.** `Api\Client` asks its `TokenSource` (`OAuth\ApiTokens`) for a token before each call. The token is refreshed when it expires within 60 seconds. When the API answers 401, the client refreshes and repeats the call **once**, with or without an idempotency key, because a rejected request changed nothing. Only a call with an idempotency key is repeated after a network error or 5xx.

Rotating refresh tokens make parallel refreshes dangerous: the second request would use a token that is already spent. `Service::refresh()` therefore takes a transient lock (`bitsnap_oauth_refresh_<env>`, 30 s). The request that gets the lock re-reads the tokens **from the database** (`Settings::reload()` drops this request's cached copy) and refreshes only if they are still the stale ones. A request that loses the lock waits up to five seconds, re-reads the stored tokens, and uses the winner's. It never uses the refresh token itself. If the lock never clears the call fails with `busy` and the refresh token stays unused.

Failures:

| Answer of the token endpoint | What the plugin does |
| --- | --- |
| `invalid_client` with status **400 or 401** (RFC 6749, RFC 7591), read from the JSON `error` field, not the status alone | Forget the client, register again, retry once |
| `invalid_grant` (revoked, or the refresh token was already used) | Clear the OAuth tokens of **that environment**, so it falls back to its manual key and the settings page offers "Connect with Bitsnap" again. API calls fail with a clear "connect again" message |
| Network error, 5xx | Keep the tokens, report "could not be reached" |
| A 401 without a JSON `invalid_client` | Not a lost registration. No re-registration |

**Disconnect.** `OAuthActions::disconnect()` deletes the webhook (needs the token, so it goes first), posts `{base}/oauth/revoke` with the refresh token and client id, then clears the tokens, the webhook id and the webhook secret of that environment only. If Bitsnap does not confirm the deletion or the revocation, the store is disconnected anyway and the merchant gets a warning that points to "Connected apps" in Bitsnap.

Nothing in the OAuth code logs. Errors carry a short code (`OAuthException::error_name()`), never a response body, and `Admin\OAuthMessages` turns it into text.

### Webhook registration

`WebhooksService.CreateWebhook` answers `{ webhook: { id, url, … }, signingSecret }`. `Webhook\Registrar` stores `webhook.id` as `webhook_id_<env>` and the secret as `webhook_secret_<env>`, for the OAuth connection and for the manual "Register webhook" button alike. `DeleteWebhook` takes `{ "id": "<webhook id>" }`, not the URL. Without a stored id (a site that registered before ids were stored) the registrar calls `ListWebhooks`, matches the URL, and deletes by that id. If the list needs a permission the key lacks, nothing is deleted and a secret the merchant pasted stays. A webhook that is already gone (404) counts as deleted.

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

### Credentials provider

`Credentials` is the only class that knows where API credentials come from. `mode( $environment )` is `oauth` when an access token is stored for the environment and `manual` otherwise. `api_key()` returns the OAuth access token when there is one (the client refreshes it as needed), else the manual key. The webhook secret is the same field in both modes.

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
- Without a persistent object cache the refresh lock is a transient in the options table, so two requests can in theory both take it. The request that wins the lock re-reads the tokens from the database before it refreshes, which closes the common case, but a rotated token spent twice would end in `invalid_grant` and a reconnect.
- If Bitsnap forgets the client registration, the merchant sees an error on the Bitsnap consent page, where the plugin cannot catch it. Re-registration happens when the token endpoint reports `invalid_client`. A fix by hand: change the API base URL and back, or disconnect and connect.
- Only two-decimal currencies are supported.
- `process_refund()` finds the WooCommerce refund it is processing as the newest refund of the order. WooCommerce creates that record before it calls the gateway, and does not pass its id.
