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

Bitsnap for WooCommerce adds a payment method to your checkout. When a buyer chooses it, the plugin creates a payment in your [Bitsnap](https://bitsnap.pl) project and sends the buyer to the Bitsnap payment page. There they pay with any method you enabled in the project: BLIK, PayU, Przelewy24, Stripe, imoje, cash on delivery or a manual bank transfer. Bitsnap tells your shop when the payment succeeds, fails or expires, and WooCommerce updates the order.

**Features**

* Works with the Cart and Checkout Blocks and with the classic checkout.
* Compatible with High-Performance Order Storage.
* Test mode and live mode with separate API keys and webhook secrets.
* Order status follows the payment: paid, failed, cancelled. Cash on delivery moves the order to "processing", a manual transfer to "on hold" until the money arrives.
* Refunds from the WooCommerce order screen, full or partial, for payment methods that can be refunded through Bitsnap. Refunds you make in the Bitsnap dashboard also appear in WooCommerce.
* Sends the cart as it is in WooCommerce: lines with VAT rates, shipping, fees, coupon discounts and the exact total.
* Reads the buyer's NIP from the invoice plugin you already use. The plugin adds no checkout field.
* Checks pending orders again when the buyer returns to the shop, and every 15 minutes in the background, so a delayed webhook does not leave an order unpaid.
* API keys and webhook secrets are stored encrypted. The debug log hides secrets.
* Polish and English.

**You need**

* A Bitsnap account and project (https://bitsnap.pl).
* A test API key, and later a live one, with the permissions `checkout:create`, `orders:read` and `refunds:write`. Add `customers:pii` to send buyer details and `webhooks:write` to register the webhook from the settings page.
* A shop that uses PLN, EUR, USD or GBP. The currency must also be enabled in your Bitsnap project.

== External services ==

This plugin connects to the Bitsnap service (https://bitsnap.pl), operated by Bitsnap, to create and check payments and refunds. A Bitsnap account is required. The plugin cannot take payments without it.

The base address is https://bitsnap.pl. It can be changed under "Advanced" in the gateway settings.

**1. Starting a payment.** When a buyer places an order with this payment method, the plugin sends a request to `https://bitsnap.pl/api/v1/bitsnap.v1.CheckoutService/CreateCheckoutSession`. It sends: the order lines (name, SKU, quantity, gross price, VAT rate, product image URL), shipping, fees, discount, order total and currency; the order number, order key, your site address and the plugin version; the return and cancel URLs; the language; and, if your API key allows it, the buyer's email, name, phone number, company, tax number (NIP) and billing and shipping address. The buyer's browser is then redirected to the Bitsnap payment page, where Bitsnap processes the payment.

**2. Checking a payment.** When the buyer returns to the shop and the order is still unpaid, and every 15 minutes for orders that stay unpaid for up to 48 hours, the plugin sends the order number to `https://bitsnap.pl/api/v1/bitsnap.v1.OrdersService/GetOrder` and reads the payment status.

**3. Refunds.** When you refund an order from the WooCommerce admin, the plugin sends the Bitsnap order ID, the amount and the reason to `https://bitsnap.pl/api/v1/bitsnap.v1.RefundsService/CreateRefund`.

**4. Registering the webhook.** Only when an administrator clicks "Register webhook" in the settings, the plugin calls `https://bitsnap.pl/api/v1/bitsnap.v1.WebhooksService/ListWebhooks` and `https://bitsnap.pl/api/v1/bitsnap.v1.WebhooksService/CreateWebhook`. It sends the address of your webhook endpoint (`/?wc-api=bitsnap` on your site) and your site's host name as a label.

**5. Webhooks from Bitsnap.** Bitsnap sends signed payment and refund events to your shop at `/?wc-api=bitsnap`. The plugin accepts them only with a valid signature.

Every request carries your API key. Bitsnap Terms of Service: https://bitsnap.pl/regulamin. Bitsnap Privacy Policy: https://bitsnap.pl/polityka-prywatnosci.

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. In Bitsnap, create a test API key.
3. Go to WooCommerce > Settings > Payments > Bitsnap. Paste the test API key and save.
4. Click "Register webhook" under the test credentials. If your key cannot create webhooks, create one in Bitsnap for the URL shown on the page and paste its secret.
5. Place a test order and pay it in test mode.
6. Repeat for the live key, then turn off "Test mode" and enable the payment method.

== Frequently Asked Questions ==

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

== Changelog ==

= 0.1.0 =
* First release: redirect gateway, Cart and Checkout Blocks, HPOS, webhooks, return-page check, scheduled reconciliation, refunds, test and live mode.

== Upgrade Notice ==

= 0.1.0 =
First release.
