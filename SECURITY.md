# Security policy

## Reporting a vulnerability

Please report security problems privately. Do not open a public issue.

Use [GitHub private vulnerability reporting](https://github.com/emmems/bitsnap-woocommerce/security/advisories/new) (the "Report a vulnerability" button on the Security tab).

Include the plugin version, what you found, how to reproduce it, and what an attacker could do with it. Do not include real API keys or webhook secrets.

We acknowledge a report within 3 working days and aim to ship a fix, or tell you why not, within 30 days. We credit reporters who want it.

## Supported versions

Only the latest released version gets security fixes.

## What is in scope

- The webhook endpoint (`/?wc-api=bitsnap`): signature check, replay, order matching.
- Storage and logging of API keys and webhook secrets.
- Admin actions (nonces, capabilities).
- Anything that lets a buyer or an outsider change an order's payment status.

Problems in Bitsnap's own service (bitsnap.pl) or in WooCommerce and WordPress core are out of scope here. Report those to their owners.

## How the plugin handles secrets

- API keys and webhook secrets are stored encrypted with libsodium, using a key derived from the site's `AUTH_KEY` and `SECURE_AUTH_KEY`. This protects database dumps and backups. It does not protect against someone who can read `wp-config.php`. If the sodium extension is missing, secrets are stored in plain text and an admin notice says so.
- The debug log redacts keys, tokens, secrets and `Authorization` headers.
- Webhooks are accepted only with a valid HMAC-SHA512 signature, compared in constant time.
