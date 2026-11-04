<?php
/**
 * The Bitsnap payment gateway.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

use Bitsnap\WooCommerce\Api\ApiException;
use Bitsnap\WooCommerce\Credentials;
use Bitsnap\WooCommerce\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect gateway: `process_payment()` creates a Bitsnap checkout session and sends the
 * buyer to its URL. Works with the classic checkout and with the Cart and Checkout Blocks.
 */
class Gateway extends \WC_Payment_Gateway {

	/** All gateway identifiers sharing this integration. */
	public const IDS = array( 'bitsnap', 'bitsnap_manual', 'bitsnap_cod' );

	/**
	 * Gateway identifier.
	 *
	 * @return string
	 */
	protected function gateway_id(): string {
		return 'bitsnap';
	}

	/**
	 * Shared settings option.
	 *
	 * @return string
	 */
	public function get_option_key() {
		return \Bitsnap\WooCommerce\Support\Settings::OPTION;
	}

	/**
	 * Request enum for a WooCommerce gateway id.
	 *
	 * @param string $id Gateway identifier.
	 * @return string
	 */
	public static function payment_method( string $id ): string {
		return 'CHECKOUT_PAYMENT_METHOD_' . ( 'bitsnap_cod' === $id ? 'COD' : ( 'bitsnap_manual' === $id ? 'MANUAL' : 'ONLINE' ) );
	}

	/**
	 * Presentation field prefix in shared storage.
	 *
	 * @return string
	 */
	private function presentation_prefix(): string {
		return 'bitsnap' === $this->id ? '' : $this->id . '_';
	}

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin             = Plugin::instance();
		$this->id                 = $this->gateway_id();
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = 'bitsnap_manual' === $this->id ? __( 'Bitsnap: traditional bank transfer', 'bitsnap-for-woocommerce' ) : ( 'bitsnap_cod' === $this->id ? __( 'Bitsnap: cash on delivery', 'bitsnap-for-woocommerce' ) : __( 'Bitsnap', 'bitsnap-for-woocommerce' ) );
		$this->method_description = __( 'Accept BLIK, cards, bank transfers and more through Bitsnap. The buyer pays on a secure Bitsnap page.', 'bitsnap-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( $this->presentation_prefix() . 'title' );
		$this->description = $this->get_option( $this->presentation_prefix() . 'description' );
		$this->enabled     = $this->get_option( $this->presentation_prefix() . 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Currencies the gateway offers itself for.
	 *
	 * @return string[]
	 */
	public static function supported_currencies(): array {
		/**
		 * Filters the currencies Bitsnap is offered for at checkout.
		 *
		 * Only two-decimal currencies work. The project must also accept the currency.
		 *
		 * @param string[] $currencies ISO 4217 codes.
		 */
		$currencies = apply_filters( 'bitsnap_wc_supported_currencies', array( 'PLN', 'EUR', 'USD', 'GBP' ) );
		return array_values( array_map( 'strtoupper', array_filter( (array) $currencies, 'is_string' ) ) );
	}

	/**
	 * Whether the gateway is offered at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( ! in_array( strtoupper( get_woocommerce_currency() ), self::supported_currencies(), true ) ) {
			return false;
		}
		if ( 'bitsnap_cod' === $this->id ) {
			$order    = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			$shipping = $order instanceof \WC_Order ? OrderReader::needs_shipping( $order ) : ( WC()->cart && WC()->cart->needs_shipping() );
			if ( ! $shipping ) {
				return false;
			}
		}
		return $this->plugin->credentials()->is_configured();
	}

	/**
	 * Description shown at checkout, with a test mode notice.
	 *
	 * @return string
	 */
	public function get_description() {
		$description = parent::get_description();
		if ( Credentials::ENV_TEST === $this->plugin->credentials()->environment() ) {
			$description = trim( $description . ' ' . __( 'TEST MODE: no real money is charged.', 'bitsnap-for-woocommerce' ) );
		}
		return $description;
	}

	/**
	 * Settings fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$webhook_url = self::webhook_url();

		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'bitsnap-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Bitsnap', 'bitsnap-for-woocommerce' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'bitsnap-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What the buyer sees as the payment method name at checkout.', 'bitsnap-for-woocommerce' ),
				'default'     => __( 'Online payment (BLIK, card, bank transfer)', 'bitsnap-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'bitsnap-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Text under the payment method name at checkout.', 'bitsnap-for-woocommerce' ),
				'default'     => __( 'You will be redirected to a secure Bitsnap page to pay.', 'bitsnap-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'testmode'    => array(
				'title'       => __( 'Test mode', 'bitsnap-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the test API key. No real money moves.', 'bitsnap-for-woocommerce' ),
				'description' => __( 'Switch it off when you are ready to take real payments.', 'bitsnap-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
		);

		if ( 'bitsnap' !== $this->id ) {
			$manual                                      = 'bitsnap_manual' === $this->id;
			$this->form_fields['title']['default']       = $manual ? __( 'Traditional bank transfer', 'bitsnap-for-woocommerce' ) : __( 'Cash on delivery', 'bitsnap-for-woocommerce' );
			$this->form_fields['description']['default'] = $manual ? __( 'Pay by bank transfer using the details on the Bitsnap page.', 'bitsnap-for-woocommerce' ) : __( 'Pay when your order is delivered.', 'bitsnap-for-woocommerce' );
			unset( $this->form_fields['testmode'] );
			foreach ( array( 'enabled', 'title', 'description' ) as $key ) {
				$this->form_fields[ $this->presentation_prefix() . $key ] = $this->form_fields[ $key ];
				unset( $this->form_fields[ $key ] );
			}
			return;
		}

		$this->form_fields['section_advanced'] = array(
			'title' => __( 'Advanced', 'bitsnap-for-woocommerce' ),
			'type'  => 'title',
		);

		foreach ( array( 'test', 'live' ) as $environment ) {
			$is_test = 'test' === $environment;

			$this->form_fields[ 'section_' . $environment ]        = array(
				'title'       => $is_test ? __( 'Test credentials', 'bitsnap-for-woocommerce' ) : __( 'Live credentials', 'bitsnap-for-woocommerce' ),
				'type'        => 'title',
				'description' => $this->credentials_help( $environment, $webhook_url ),
			);
			$this->form_fields[ 'api_key_' . $environment ]        = array(
				'title'       => $is_test ? __( 'Test API key', 'bitsnap-for-woocommerce' ) : __( 'Live API key', 'bitsnap-for-woocommerce' ),
				'type'        => 'bitsnap_secret',
				'description' => $is_test
					? __( 'Starts with bs_test_. Needs the checkout:create, orders:read and refunds:write permissions, and customers:pii to send buyer details.', 'bitsnap-for-woocommerce' )
					: __( 'Starts with bs_live_. Needs the same permissions as the test key.', 'bitsnap-for-woocommerce' ),
				'desc_tip'    => true,
			);
			$this->form_fields[ 'webhook_secret_' . $environment ] = array(
				'title'       => $is_test ? __( 'Test webhook secret', 'bitsnap-for-woocommerce' ) : __( 'Live webhook secret', 'bitsnap-for-woocommerce' ),
				'type'        => 'bitsnap_secret',
				'description' => __( 'Starts with whsec_. Filled in for you by "Register webhook", or paste the secret of a webhook you created in Bitsnap.', 'bitsnap-for-woocommerce' ),
				'desc_tip'    => true,
			);
		}

		$this->form_fields['api_base_url'] = array(
			'title'       => __( 'API base URL', 'bitsnap-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'Leave as it is unless Bitsnap support tells you to change it.', 'bitsnap-for-woocommerce' ),
			'default'     => Credentials::DEFAULT_BASE_URL,
			'desc_tip'    => true,
		);
		$this->form_fields['debug']        = array(
			'title'       => __( 'Debug log', 'bitsnap-for-woocommerce' ),
			'type'        => 'checkbox',
			'label'       => __( 'Log API calls and webhooks', 'bitsnap-for-woocommerce' ),
			'description' => sprintf(
				/* translators: %s: link to the WooCommerce logs page. */
				__( 'Writes to WooCommerce > Status > Logs (source "bitsnap"). Keys and secrets are removed from the log. %s', 'bitsnap-for-woocommerce' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'Open logs', 'bitsnap-for-woocommerce' ) . '</a>'
			),
			'default'     => 'no',
		);
	}

	/**
	 * URL Bitsnap calls with events.
	 *
	 * @return string
	 */
	public static function webhook_url(): string {
		return add_query_arg( 'wc-api', 'bitsnap', home_url( '/' ) );
	}

	/**
	 * Help text of a credentials section: "Connect with Bitsnap" or the connection state, and the webhook URL.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @param string $webhook_url Webhook URL.
	 * @return string HTML.
	 */
	private function credentials_help( string $environment, string $webhook_url ): string {
		$oauth = $this->plugin->oauth();

		if ( $oauth->connected( $environment ) ) {
			$info       = $oauth->info( $environment );
			$disconnect = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'bitsnap_oauth_disconnect',
						'env'    => $environment,
					),
					admin_url( 'admin-post.php' )
				),
				'bitsnap_oauth_disconnect_' . $environment
			);
			return '<p>' . sprintf(
				/* translators: 1: Bitsnap project name, 2: environment (test or live). */
				esc_html__( 'Connected to %1$s (%2$s).', 'bitsnap-for-woocommerce' ),
				'<strong>' . esc_html( $info['project_name'] ) . '</strong>',
				esc_html( $environment )
			) . ' <a class="button" href="' . esc_url( $disconnect ) . '">' . esc_html__( 'Disconnect', 'bitsnap-for-woocommerce' ) . '</a></p><p>' . sprintf(
				/* translators: %s: webhook URL. */
				esc_html__( 'Webhook URL: %s. It was registered in Bitsnap when you connected. While connected, the API key below is not used.', 'bitsnap-for-woocommerce' ),
				'<code>' . esc_html( $webhook_url ) . '</code>'
			) . '</p>';
		}

		$connect  = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'bitsnap_oauth_connect',
					'env'    => $environment,
				),
				admin_url( 'admin-post.php' )
			),
			'bitsnap_oauth_connect_' . $environment
		);
		$register = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'bitsnap_register_webhook',
					'env'    => $environment,
				),
				admin_url( 'admin-post.php' )
			),
			'bitsnap_register_webhook_' . $environment
		);

		return '<p><a class="button button-primary" href="' . esc_url( $connect ) . '">' . esc_html__( 'Connect with Bitsnap', 'bitsnap-for-woocommerce' ) . '</a></p><p>' . sprintf(
			/* translators: 1: webhook URL, 2: opening link tag for the Register webhook button, 3: closing link tag. */
			__( 'Webhook URL: %1$s. Or enter an API key below and save it, then %2$sRegister webhook%3$s to create the webhook in Bitsnap and store its secret.', 'bitsnap-for-woocommerce' ),
			'<code>' . esc_html( $webhook_url ) . '</code>',
			'<a class="button button-secondary" href="' . esc_url( $register ) . '">',
			'</a>'
		) . '</p>';
	}

	/**
	 * Renders a secret field: never shows the stored value.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string HTML.
	 */
	public function generate_bitsnap_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'class'       => '',
				'css'         => '',
				'description' => '',
				'desc_tip'    => false,
			)
		);
		$stored    = $this->plugin->settings()->get( $key );
		$broken    = $this->plugin->settings()->is_secret_broken( $key );

		if ( $broken ) {
			$placeholder = __( 'Saved, but it cannot be read. Enter it again.', 'bitsnap-for-woocommerce' );
		} elseif ( '' !== $stored ) {
			$placeholder = __( 'Saved. Enter a new value to replace it.', 'bitsnap-for-woocommerce' );
		} else {
			$placeholder = '';
		}

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by WooCommerce. ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<input class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>" type="password" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" spellcheck="false" />
					<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by WooCommerce. ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Validates a secret field. An empty field keeps the stored secret.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string Value to store (encrypted).
	 */
	public function validate_bitsnap_secret_field( $key, $value ) {
		$settings = $this->plugin->settings();
		$value    = is_null( $value ) ? '' : trim( sanitize_text_field( wp_unslash( $value ) ) );
		if ( '' === $value ) {
			return $settings->get( $key );
		}

		$prefixes = array(
			'api_key_test'        => 'bs_test_',
			'api_key_live'        => 'bs_live_',
			'webhook_secret_test' => 'whsec_',
			'webhook_secret_live' => 'whsec_',
		);
		if ( isset( $prefixes[ $key ] ) && 0 !== strpos( $value, $prefixes[ $key ] ) ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: the prefix the value must start with. */
					__( 'The value was not saved: it must start with %s.', 'bitsnap-for-woocommerce' ),
					$prefixes[ $key ]
				)
			);
			return $settings->get( $key );
		}
		return $settings->seal( $value );
	}

	/**
	 * Validates the API base URL.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 */
	public function validate_api_base_url_field( $key, $value ) {
		$value = is_null( $value ) ? '' : esc_url_raw( trim( wp_unslash( $value ) ), array( 'https', 'http' ) );
		if ( '' !== $value && ! \Bitsnap\WooCommerce\Support\ApiUrl::valid( $value ) ) {
			\WC_Admin_Settings::add_error( __( 'Use a public HTTPS Bitsnap API URL.', 'bitsnap-for-woocommerce' ) );
			return $this->plugin->credentials()->base_url();
		}
		return '' === $value ? Credentials::DEFAULT_BASE_URL : rtrim( $value, '/' );
	}

	/**
	 * Starts the payment.
	 *
	 * @param int $order_id Order id.
	 * @return array{result:string,redirect?:string}
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wc_add_notice( __( 'The order was not found. Please try again.', 'bitsnap-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		try {
			$redirect = $this->plugin->starter()->start( $order, $this->get_return_url( $order ) );
		} catch ( ApiException $e ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: reason the payment could not be started. */
					__( 'Bitsnap could not start the payment: %s', 'bitsnap-for-woocommerce' ),
					ErrorMessages::describe( $e )
				)
			);
			wc_add_notice( __( 'We could not start the payment. Please try again or choose another payment method.', 'bitsnap-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Refunds through Bitsnap.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount in major units.
	 * @param string     $reason   Reason.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return $this->plugin->refunder()->refund( (int) $order_id, $amount, (string) $reason );
	}
}
