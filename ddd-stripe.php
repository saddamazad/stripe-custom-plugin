<?php
/**
 * Plugin Name: DDD Stripe Gateway
 * Description: Pay securely using credit or debit card
 * Version: 1.0.1
 * Author: Saddam Hossain Azad
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'stripe-php/init.php';
require_once plugin_dir_path( __FILE__ ) . 'inc/woo-functions.php';

add_filter( 'woocommerce_payment_gateways', function ( $methods ) {
    $methods[] = 'WC_Gateway_DDD_Stripe';
    return $methods;
});

add_action( 'plugins_loaded', function () {
    class WC_Gateway_DDD_Stripe extends WC_Payment_Gateway {
        public function __construct() {
            $this->id                 = 'ddd_stripe';
            $this->method_title       = 'DDD Stripe';
            $this->method_description = 'Pay securely using credit or debit card';
            $this->has_fields         = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title        = $this->get_option( 'title' );
            $this->description  = $this->get_option( 'description' );
			$this->testmode = ( 'yes' === $this->get_option( 'testmode' ) );

			if ( $this->testmode ) {
				$this->publishable = $this->get_option( 'test_publishable_key' );
				$this->secret_key = $this->get_option( 'test_secret_key' );
			} else {
				$this->publishable = $this->get_option( 'live_publishable_key' );
				$this->secret_key = $this->get_option( 'live_secret_key' );
			}

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [
                $this,
                'process_admin_options'
            ]);

            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
			
			if ( is_admin() && 'yes' === $this->enabled ) {
				if ( empty( $this->publishable ) || empty( $this->secret_key ) ) {
					add_action( 'admin_notices', function () {
						echo '<div class="notice notice-error"><p><strong>DDD Stripe Gateway:</strong> API keys are missing.</p></div>';
					});
				}
			}
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'default' => 'yes',
                ],
				'testmode' => [
					'title'       => 'Test mode',
					'label'       => 'Enable Stripe test mode',
					'type'        => 'checkbox',
					'default'     => 'yes',
					'description' => 'Use Stripe test keys for testing payments',
				],
                'title' => [
                    'title'   => 'Title',
                    'type'    => 'text',
                    'default' => 'Credit / Debit Card',
                ],
                'description' => [
                    'title'   => 'Description',
                    'type'    => 'textarea',
                    'default' => 'Pay securely using your card',
                ],
				'test_publishable_key' => [
					'title' => 'Test Publishable Key',
					'type'  => 'text',
				],

				'test_secret_key' => [
					'title' => 'Test Secret Key',
					'type'  => 'password',
				],

				'live_publishable_key' => [
					'title' => 'Live Publishable Key',
					'type'  => 'text',
				],

				'live_secret_key' => [
					'title' => 'Live Secret Key',
					'type'  => 'password',
				],
            ];
        }

        public function payment_fields() {
			if ( $this->testmode ) {
				//echo '<p style="color:#b81c23;font-weight:bold;">TEST MODE ENABLED — No real charges will be made.</p>';
			}
			
			echo '<p class="payment-info-title"><strong>Enter Payment Information:</strong></p>';
			echo '<div class="ddd-stripe-fields">
					<div class="stripe-card-number-wrapper">
						<div class="stripe-field-wrap">
							<label>Card number</label>
							<div id="stripe-card-number" class="stripe-field"></div>
						</div>
						<span class="stripe-card-brand">
							<!--<img src="' . plugin_dir_url( __FILE__ ) . 'assets/cards/visa.svg" />
							<img src="' . plugin_dir_url( __FILE__ ) . 'assets/cards/mastercard.svg">
							<img src="' . plugin_dir_url( __FILE__ ) . 'assets/cards/amex.svg" />-->
							
							<span class="card-brand-item visa">Visa</span>
							<span class="card-brand-item mastercard">MC</span>
							<span class="card-brand-item amex">Amex</span>
						</span>
					</div>

					<div class="stripe-expiry-cvc-container">
						<div class="stripe-field-wrap">
							<label>Expiration date</label>
							<div id="stripe-card-expiry" class="stripe-field"></div>
						</div>

						<div class="stripe-field-wrap">
							<label>Security code</label>
							<div id="stripe-card-cvc" class="stripe-field"></div>
						</div>
					</div>

					<div class="stripe-field-wrap">
						<label>ZIP code</label>
						<div id="stripe-postal-code" class="stripe-field"></div>
					</div>

					<div id="ddd-stripe-errors" class="stripe-errors"></div>
				</div>';
        }

        public function enqueue_scripts() {
            if ( ! is_checkout() ) return;

            wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/' );
            wp_enqueue_script(
                'ddd-stripe',
                plugin_dir_url( __FILE__ ) . 'ddd-stripe.js',
                [ 'jquery', 'stripe-js' ],
                null,
                true
            );
			wp_enqueue_style('ddd-stripe', plugin_dir_url( __FILE__ ) . 'ddd-stripe.css');

            wp_localize_script( 'ddd-stripe', 'dddStripe', [
                'key' => $this->publishable,
				'assets' => plugin_dir_url( __FILE__ ) . 'assets/cards/',
            ]);
        }

        public function process_payment( $order_id ) {
			if ( empty( $_POST['stripe_payment_method'] ) ) {
				wc_add_notice(
					__( 'Payment could not be processed. Please try again.', 'woocommerce' ),
					'error'
				);
				return [ 'result' => 'failure' ];
			}
			
			if ( empty( $this->publishable ) || empty( $this->secret_key ) ) {
				wc_add_notice(
					__( 'Payment gateway is not configured. Please contact the store admin.', 'woocommerce' ),
					'error'
				);
				return [ 'result' => 'failure' ];
			}
			
            $order = wc_get_order( $order_id );

            //require_once __DIR__ . '/stripe-php/init.php';
            
            \Stripe\Stripe::setApiKey( $this->secret_key );

            try {
				$user_id = $order->get_user_id();
				
				if ( ! empty( $_POST['billing_customer_email'] ) ) {
					$billing_email = sanitize_text_field($_POST['billing_customer_email']);
					
					update_post_meta($order->get_id(), '_billing_email', $billing_email);
					update_user_meta($user_id, 'billing_email', $billing_email);
					
					//update_post_meta($order->get_id(), '_billing_email', get_user_meta($user_id, 'billing_customer_email', true));
					//update_user_meta($user_id, 'billing_email', get_user_meta($user_id, 'billing_customer_email', true));
				} else {
					$billing_email = $order->get_billing_email();
				}
				
				$billing_name  = $order->get_formatted_billing_full_name();
				
				$username = '';

				$stripe_customer_id = '';

				// Try to reuse an existing Stripe customer
				if ( $user_id ) {
					$stripe_customer_id = get_user_meta( $user_id, '_stripe_customer_id', true );
					
					//$stripe_customer_id = get_post_meta( $order_id, '_stripe_customer_id', true );
					
					$user = get_user_by( 'id', $user_id );
					if ( $user ) {
						$username = $user->user_login;
					}
				}
				
				$customer_desc = '';

				if ( $username ) {
					$customer_desc = "Name: ".$billing_name.", Username: ".$username;
				}

				// Create or update customer
				if ( $stripe_customer_id ) {
					$customer = \Stripe\Customer::update(
						$stripe_customer_id,
						[
							'email'       => $billing_email,
							'name'        => $billing_name,
							'description' => $customer_desc,
						]
					);
				} else {
					$customer = \Stripe\Customer::create([
						'email'       => $billing_email,
						'name'        => $billing_name,
						'description' => $customer_desc,
					]);

					// Save for reuse
					if ( $user_id ) {
						update_user_meta( $user_id, '_stripe_customer_id', $customer->id );
						
						update_post_meta( $order_id, '_stripe_customer_id', $customer->id );
					}
					
					$stripe_customer_id = $customer->id;
				}
				
				$payment_desc = "Delaware Defensive Driving Course - Order ".$order_id;
				
                $payment_intent = \Stripe\PaymentIntent::create([
                    'amount'   => intval( $order->get_total() * 100 ),
                    'currency' => strtolower( get_woocommerce_currency() ),
                    'payment_method' => sanitize_text_field( $_POST['stripe_payment_method'] ),
                    'confirm'  => true,
					'customer'    => $stripe_customer_id,
					'description' => $payment_desc,
					'automatic_payment_methods' => [
						'enabled' => true,
						'allow_redirects' => 'never',
					],
					'metadata' => [
						'order_id' => $order->get_id(),
						//'customer_name'  => $order->get_formatted_billing_full_name(),
        				//'customer_email' => $order->get_billing_email(),
					],
                ]);
				
				$payment_intent_id = $payment_intent->id;
				
				$order->add_order_note(
					sprintf(
						'Stripe payment intent created (Payment Intent ID: %s)',
						$payment_intent_id
					)
				);
				
				$order->update_meta_data( '_stripe_payment_intent', $payment_intent_id );
				
				$intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
				/*$intent = \Stripe\PaymentIntent::confirm(
					$payment_intent_id,
					[
						'payment_method' => $payment_method_id,
					]
				);*/

				if ( 'succeeded' === $intent->status ) {

					$charge_id = $intent->latest_charge;

					$order->payment_complete( $charge_id );
					
					// Set transaction ID manually
					//$order->set_transaction_id( $charge_id );
					
					// Save order
					//$order->save();
					
					// Complete payment WITHOUT txn id
					//$order->payment_complete();

					$order->add_order_note(
						sprintf(
							'Stripe charge complete (Charge ID: %s)',
							$charge_id
						)
					);
					
					$order->update_meta_data( '_stripe_charge_id', $charge_id );
					
					$order->save();

					return [
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
						'client_secret' => $intent->client_secret,
					];
				}
				
				// Payment not successful
    			throw new Exception( 'Payment not completed.' );

			} catch ( \Stripe\Exception\CardException $e ) {

				// Card declined / insufficient funds
				wc_add_notice( $e->getError()->message, 'error' );

				$order->add_order_note(
					sprintf(
						'Stripe payment failed: %s',
						$e->getError()->message
					)
				);

				// Keep order pending
				$order->update_status( 'pending' );

				return [
					'result' => 'failure',
				];
			
			} catch ( Exception $e ) {
                wc_add_notice( $e->getMessage(), 'error' );
                return [ 'result' => 'failure' ];
            }
        }
    }
});


// the webhook handler
add_action('rest_api_init', function () {
    register_rest_route('ddd-stripe/v1', '/webhook', [
        'methods'  => 'POST',
        'callback' => 'ddd_handle_stripe_webhook',
        'permission_callback' => '__return_true',
    ]);
});

function ddd_handle_stripe_webhook(WP_REST_Request $request) {
    $payload = $request->get_body();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $endpoint_secret = 'whsec_nXYgtVaoiMM2AQ9cb6QcHjgYWjJpdAaA'; // update the webhook secret with the live one

    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $sig_header,
            $endpoint_secret
        );
    } catch (\UnexpectedValueException $e) {
        return new WP_REST_Response('Invalid payload', 400);
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        return new WP_REST_Response('Invalid signature', 400);
    }

    // Handle events
    switch ($event->type) {

        case 'payment_intent.succeeded':
            $intent = $event->data->object;
            // Update order status
            break;

        case 'payment_intent.payment_failed':
            $intent = $event->data->object;
            // Add order note, mark failed
            
            //wp_mail("officialmail20@gmail.com", "Payment Failed", "Stripe payment failed notification sent from the Stripe server");
			
            break;

        case 'charge.refunded':
            $charge = $event->data->object;            
			//$payload = json_decode( $charge, true );
			
			$order_id = $charge->metadata->order_id;
			$refund_id = $charge->refunds->data[0]->id;
			//$stripe_refund_amount = $charge->amount;
			$stripe_refund_amount = $charge->refunds->data[0]->amount;
			$charge_id = $charge->id;
			
			$order = wc_get_order( (int) $order_id );
			
			if ( ! $order ) {
				return; // order not found
			}
			
			
			// Prevent duplicate refunds
			$existing_refunds = $order->get_refunds();
			
			foreach ( $existing_refunds as $existing_refund ) {
				if ( $existing_refund->get_meta( '_stripe_refund_id' ) === $refund_id ) {
					return; // already synced
				}
			}
			
			
			// Create the WooCommerce refund
			$refund_amount = $stripe_refund_amount / 100; // e.g. 1995 → 19.95
			
			$refund = wc_create_refund( [
				'amount'     => $refund_amount,
				'reason'     => 'Refunded via Stripe Dashboard',
				'order_id'   => $order->get_id(),
				'line_items' => [], // empty = refund order total
				'refund_payment' => false, // Stripe already refunded it
				'restock_items'  => false,
			] );
			
			
			// Attach Stripe metadata to the refund
			if ( $refund && ! is_wp_error( $refund ) ) {
				$refund->update_meta_data( '_stripe_refund_id', $refund_id );
				$refund->update_meta_data( '_stripe_charge_id', $charge_id );
				$refund->save();
			}
			
			// Update order status
			$total_refunded = $order->get_total_refunded();
			$order_total    = $order->get_total();

			if ( $total_refunded >= $order_total ) {
				/* ###  NO NEED TO CHANGE THE STATUS MANUALLY, WOOCOMMERCE DOES IT AUTOMATICALLY  ### */
				//$order->update_status( 'refunded' ); // Fully refunded
				
				// Remove course access
				$user_id = $order->get_user_id();

				if ( ! $user_id ) {
					return;
				}

				foreach ( $order->get_items() as $item ) {
					$product_id = $item->get_product_id();

					$course_ids = ddd_get_learndash_courses_from_product( $product_id );

					if ( empty( $course_ids ) ) {
						continue;
					}

					foreach ( $course_ids as $course_id ) {
						// Remove LearnDash access
						/*ld_update_course_access( $user_id, $course_id, true );*/
						
						/*if ( should_remove_course_access($user_id, $course_id, $order_id) ) {
							// Remove LearnDash access
							ld_update_course_access( $user_id, $course_id, true );
						}*/
					}
				}
			} else {
				//$order->update_status( 'partially-refunded' );
			}
			
			$order->add_order_note(
				sprintf(
					'Refund synced from Stripe Dashboard. Refund ID: %s',
					$refund_id
				)
			);
			
			//wp_mail("saddam987020@gmail.com", "Payment Refunded", "Order ID: {$order_id}, Refund ID: {$refund_id}");
            break;
    }

    return new WP_REST_Response(['status' => 'success'], 200);
}
