# Contributing

Thanks for helping. This plugin moves money, so we keep changes small and well tested.

## Before you start

- For anything bigger than a small fix, open an issue first so we can agree on the approach.
- Security problems go through [SECURITY.md](SECURITY.md), not a public issue.

## Setup

See the **Develop** section of [README.md](README.md). Docker and Node are enough.

## Rules

- **Coding standard.** WordPress-Extra and WordPress-Docs, plus PHPCompatibilityWP for PHP 7.4 to 8.4. `phpcs` must pass. Use `phpcbf` for formatting.
- **PHP 7.4.** No `match`, union types, named arguments, constructor promotion, nullsafe operator or `str_contains`. Typed properties and arrow functions are fine.
- **No runtime Composer packages.** Classes load through `src/Autoloader.php`. Composer is for development tools only.
- **Pure where possible.** Put logic that does not need WordPress or WooCommerce in a class that does not call their functions, and unit test it. `Woo\OrderReader` is the only place that reads `WC_Order` line items.
- **Order data through `WC_Order` methods** (`get_meta`, `update_meta_data`, `save`), never `get_post_meta`, so HPOS keeps working.
- **Strings** are translatable with the text domain `bitsnap-for-woocommerce`. Run `bin/make-pot.sh` after adding or changing strings, and update `languages/bitsnap-for-woocommerce-pl_PL.po`.
- **Escape on output, sanitize on input**, check a nonce and a capability on every admin action.
- **Never log or print secrets.** Logging goes through `Support\Logger`, which redacts keys and tokens.
- **Money** is integer minor units. A change to how the request is built needs a test in `CheckoutSessionBuilderTest` that shows the lines still add up to the total.

## Pull requests

- Describe what changed and how you tested it. For payment flows, say whether you tried the classic checkout, the Blocks checkout, or both.
- Keep commits focused. CI runs PHPCS, Plugin Check and PHPUnit on PHP 7.4 to 8.4. All must be green.
- Changes to the gateway, webhook, refund or reconcile code should also pass `npm run test:smoke` (see README), ideally with HPOS on and off.

By contributing you agree to license your work under GPL-2.0-or-later.
