=== Bitsnap for WooCommerce ===
Contributors: emmems
Tags: woocommerce, payments, blik, payment gateway, przelewy24
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept BLIK, cards, bank transfers and more in WooCommerce through Bitsnap. The buyer pays on a secure Bitsnap page and returns to your shop.

== Description ==

Bitsnap for WooCommerce adds online payments, traditional bank transfer and cash on delivery to your checkout. When a buyer chooses it, the plugin creates a payment in your [Bitsnap](https://bitsnap.pl) project and sends the buyer to the Bitsnap payment page. The selected WooCommerce method opens the matching payment flow in Bitsnap; online payments use providers such as BLIK, PayU, Przelewy24, Stripe or imoje. Bitsnap tells your shop when the payment succeeds, fails or expires, and WooCommerce updates the order.

Payment methods: online (BLIK, card, online transfer), traditional bank transfer and cash on delivery have independent enable switches, titles and descriptions. The latter two are off by default and share the main Bitsnap connection and credentials. Cash on delivery is offered only for orders needing shipping. Each method supports classic and Blocks checkout. Offline refunds require returning the money yourself and recording a manual refund.

Webhook retries deduplicate by eventId, with id:event compatibility for older senders.

**Features**

* Works with the Cart and Checkout Blocks and with the classic checkout.
* Compatible with High-Performance Order Storage.
* "Connect with Bitsnap": sign in to Bitsnap, pick a project and approve. The plugin gets its own limited access and registers the webhook for you. Or paste an API key and webhook secret by hand.
* Test mode and live mode, each connected on its own. Disconnecting one leaves the other.
* Order status follows the payment: paid, failed, cancelled. Cash on delivery moves the order to "processing", a manual transfer to "on hold" until the money arrives.
* Refunds from the WooCommerce order screen, full or partial, for payment methods that can be refunded through Bitsnap. Refunds you make in the Bitsnap dashboard also appear in WooCommerce.
* Sends the cart as it is in WooCommerce: lines with VAT rates, shipping, fees, coupon discounts and the exact total.
* Reads the buyer's NIP from the invoice plugin you already use. The plugin adds no checkout field.
* Checks pending orders again when the buyer returns to the shop, and every 15 minutes in the background, so a delayed webhook does not leave an order unpaid.
* API keys and webhook secrets are stored encrypted. The debug log hides secrets.
* Polish and English.

**You need**

* A Bitsnap account and project (https://bitsnap.pl).
* Either "Connect with Bitsnap" (no key to copy), or a test API key and later a live one. A key needs the permissions `checkout:create`, `orders:read` and `refunds:write`. Add `customers:pii` to send buyer details and `webhooks:write` to register the webhook from the settings page.
* An https address for your shop to use "Connect with Bitsnap" (a `localhost` address works for development). Without https, use an API key.
* A shop that uses PLN, EUR, USD or GBP. The currency must also be enabled in your Bitsnap project.

== External services ==

This plugin connects to the Bitsnap service (https://bitsnap.pl), operated by Bitsnap, to create and check payments and refunds, and optionally to connect your store with OAuth. A Bitsnap account is required. The plugin cannot take payments without it.

The base address is https://bitsnap.pl. It can be changed under "Advanced" in the gateway settings.

**1. Starting a payment.** When a buyer places an order with this payment method, the plugin sends a request to `https://bitsnap.pl/api/v1/bitsnap.v1.CheckoutService/CreateCheckoutSession`. It sends: the order lines (name, SKU, quantity, gross price, VAT rate, product image URL), shipping, fees, discount, order total and currency; the order number, order key, your site address and the plugin version; the return and cancel URLs; the language and selected payment method; and, if your API key allows it, the buyer's email, name, phone number, company, tax number (NIP) and billing and shipping address. The buyer's browser is then redirected to the Bitsnap payment page, where Bitsnap processes the payment.

**2. Checking a payment.** When the buyer returns to the shop and the order is still unpaid, and every 15 minutes for orders that stay unpaid for up to 48 hours, the plugin sends the order number to `https://bitsnap.pl/api/v1/bitsnap.v1.OrdersService/GetOrder` and reads the payment status.

**3. Refunds.** When you refund an order from the WooCommerce admin, the plugin sends the Bitsnap order ID, the amount and the reason to `https://bitsnap.pl/api/v1/bitsnap.v1.RefundsService/CreateRefund`.

**4. The webhook.** Only when an administrator clicks "Register webhook" or "Connect with Bitsnap", or "Disconnect", the plugin calls `https://bitsnap.pl/api/v1/bitsnap.v1.WebhooksService/ListWebhooks`, `CreateWebhook` and `DeleteWebhook`. It sends the address of your webhook endpoint (`/?wc-api=bitsnap` on your site) and your site's host name as a label. Bitsnap answers with the webhook id and its signing secret, which the plugin stores (the secret encrypted) and uses to delete the webhook when you disconnect.

**5. Webhooks from Bitsnap.** Bitsnap sends signed payment and refund events to your shop at `/?wc-api=bitsnap`. The plugin accepts them only with a valid signature.

**6. Connect with Bitsnap.** Only when an administrator clicks "Connect with Bitsnap", the plugin registers your shop as an OAuth client at `https://bitsnap.pl/oauth/register`. It sends the name "Bitsnap for WooCommerce" with your site's host name, the address of the plugin's callback page in your admin, and the plugin version. Then it sends the administrator's browser to `https://bitsnap.pl/oauth/authorize`, where they sign in to Bitsnap, pick a project and approve. The plugin exchanges the returned code at `/oauth/token` (with a PKCE code verifier), and later refreshes the token there before it expires, when the shop calls Bitsnap. "Disconnect" sends the refresh token to `/oauth/revoke`. The tokens are stored encrypted in your database, and each environment (test, live) has its own. Every API request carries your API key or the OAuth access token. Bitsnap Terms of Service: https://bitsnap.pl/regulamin. Bitsnap Privacy Policy: https://bitsnap.pl/polityka-prywatnosci.

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Go to WooCommerce > Settings > Payments > Bitsnap and click "Connect with Bitsnap" under "Test credentials". Sign in, choose a project and approve access. The plugin registers the webhook and saves its secret.
3. For a manual setup instead, enter a test API key under "Test credentials", save, then click "Register webhook". If the key cannot create webhooks, create one in Bitsnap for the URL shown and paste its secret.
4. Place a test order and pay it in test mode.
5. Connect the live environment the same way, under "Live credentials". Turn off "Test mode" and enable the payment method.

== Frequently Asked Questions ==

= What does "Connect with Bitsnap" give the plugin? =

Limited access to one Bitsnap project and one environment (test or live): it can create payments, read orders, refund, and manage its own webhook. It cannot see other projects. You can end it any time with "Disconnect", or under "Connected apps" in your Bitsnap settings. If both a connection and an API key exist for an environment, the connection is used.

= The settings say my connection is no longer valid. =

Bitsnap revoked the access, or it expired. Click "Connect with Bitsnap" again. The webhook registered earlier is replaced by a new one.

= "Connect with Bitsnap" says my shop needs https. =

OAuth needs a secure address for the callback. Switch the shop to https, or use an API key. A `localhost` address works for development.

= Do buyers pay on my site? =

No. They are redirected to a Bitsnap payment page and come back after paying. Card details never reach your server.

= Which payment methods can buyers use? =

All the methods enabled in your Bitsnap project, including cash on delivery and manual bank transfer.

= Does it work with the block checkout? =

Yes. It also works with the classic shortcode checkout.

= The order stays "pending" after payment. =

Check that the webhook is registered and its secret is saved for the right mode (test or live). The plugin also asks Bitsnap for the status when the buyer returns and every 15 minutes, so the order should update within a quarter of an hour. Turn on "Debug log" in the settings and read WooCommerce > Status > Logs (source "bitsnap").

= Can I refund a cash on delivery or manual transfer order? =

Bitsnap cannot return that money for you. Refund the buyer yourself, then use "Refund manually" in WooCommerce.

= My invoice plugin stores the NIP in a custom field. =

Use the `bitsnap_wc_order_nip` filter to return it. It is validated before it is sent.

= Where is the source code? =

https://github.com/emmems/bitsnap-woocommerce. The block checkout script in `build/` is compiled from `assets/js/blocks/` with `npm install && npm run build`.

== Screenshots ==

1. The Bitsnap payment method at the WooCommerce checkout (block checkout).
2. The order confirmation after the buyer paid with BLIK on the Bitsnap payment page.
3. A paid order in WooCommerce, refunded from the order screen through Bitsnap.
4. Plugin settings: connect your Bitsnap project, or paste an API key, and switch between test and live mode.

== Changelog ==

= 0.1.0 =
* First release: redirect gateway, Cart and Checkout Blocks, HPOS, webhooks, return-page check, scheduled reconciliation, refunds, test and live mode, Connect with Bitsnap (OAuth) or manual API keys.

== Upgrade Notice ==

= 0.1.0 =
First release.
