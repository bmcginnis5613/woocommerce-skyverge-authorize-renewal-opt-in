<?php
/**
 * Plugin Name: WooCommerece SkyVerge Authorize.Net Renewal Opt-In
 * Description: Allow the customer to opt-in to automatic payments when renewing an existing manual subscription through Block Checkout.
 * Version: 1.0.4
 * Author: FirstTracks Marketing
 * Author URI: https://firsttracksmarketing.com
 * Requires Plugins: woocommerce, woocommerce-subscriptions, woocommerce-gateway-authorize-net-cim
 */

defined( 'ABSPATH' ) || exit;

final class CER_Authorize_Net {
    const NS = 'cer-authorize-net';
    const CONSENT = '_cer_authnet_consent';
    const TRANSACTION = '_cer_authnet_transaction';
    const LABEL = 'Enable Auto Renewal';
    private static $pending_orders = array();
    public static function details() {
        $store_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        return sprintf( 'By selecting this option, I authorize %s to save the payment method above to my account and automatically charge it for future membership renewals.', $store_name );
    }
    const GATEWAYS = array( 'authorize_net_cim_credit_card', 'authorize_net_cim_echeck' );

    public static function boot() {
        add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'blocks' ) );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'consent' ), 100, 2 );
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'queue' ), 100 );
        add_action( 'woocommerce_order_status_changed', function( $id, $old, $new ) {
            if ( in_array( $new, array( 'processing', 'completed' ), true ) ) { self::queue( $id ); }
        }, 100, 3 );
        add_action( 'cer_authnet_apply_opt_in', array( __CLASS__, 'apply' ) );
        add_action( 'shutdown', array( __CLASS__, 'flush' ), 100 );
        // Subscriptions renders its information at priority 15. At that point
        // the gateway has completed payment but has not yet fired its final
        // transaction-data hook, so the subscription can still appear manual.
        add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_auto_renew_notice' ), 16, 3 );
        foreach ( self::GATEWAYS as $id ) {
            add_filter( 'wc_payment_gateway_' . $id . '_process_payment', array( __CLASS__, 'force_save' ), 100, 3 );
            add_action( 'wc_payment_gateway_' . $id . '_add_transaction_data', array( __CLASS__, 'transaction' ), 100, 3 );
            add_action( 'wc_payment_gateway_' . $id . '_payment_processed', function( $order ) { self::queue( $order->get_id() ); }, 100 );
        }
        add_action( 'before_woocommerce_init', function() {
            if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
            }
        } );
    }

    public static function staging() {
        if ( is_callable( array( 'WCS_Staging', 'is_duplicate_site' ) ) ) { return WCS_Staging::is_duplicate_site(); }
        if ( is_callable( array( 'WC_Subscriptions', 'is_duplicate_site' ) ) ) { return WC_Subscriptions::is_duplicate_site(); }
        return function_exists( 'wcs_is_duplicate_site' ) && wcs_is_duplicate_site();
    }

    public static function eligible( $subscription, $user_id ) {
        return $subscription instanceof WC_Subscription && $user_id > 0
            && (int) $subscription->get_user_id() === (int) $user_id
            && $subscription->get_requires_manual_renewal()
            && $subscription->has_status( array( 'active', 'on-hold' ) )
            && $subscription->get_time( 'next_payment' ) > 0;
    }

    public static function cart_targets() {
        $ids = array();
        if ( ! self::allowed_gateways() || ! function_exists( 'wcs_get_subscription' ) || ! WC()->cart || ! get_current_user_id() ) { return $ids; }
        foreach ( WC()->cart->get_cart() as $item ) {
            $id = isset( $item['subscription_renewal']['subscription_id'] ) ? absint( $item['subscription_renewal']['subscription_id'] ) : 0;
            if ( $id && self::eligible( wcs_get_subscription( $id ), get_current_user_id() ) ) { $ids[] = $id; }
        }
        return array_values( array_unique( $ids ) );
    }

    public static function blocks() {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) { return; }
        register_block_type( __DIR__ . '/assets' );
        woocommerce_store_api_register_endpoint_data( array(
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
            'namespace' => self::NS,
            'data_callback' => function() { return array( 'eligible' => (bool) self::cart_targets(), 'allowed_gateways' => self::allowed_gateways() ); },
            'schema_callback' => function() { return array(
                'eligible' => array( 'type' => 'boolean', 'readonly' => true ),
                'allowed_gateways' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'readonly' => true ),
            ); },
            'schema_type' => ARRAY_A,
        ) );
        woocommerce_store_api_register_endpoint_data( array(
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace' => self::NS,
            'schema_callback' => function() { return array( 'opt_in' => array( 'type' => 'boolean' ) ); },
            'schema_type' => ARRAY_A,
        ) );
        require_once __DIR__ . '/class-block-integration.php';
        add_action( 'woocommerce_blocks_checkout_block_registration', function( $registry ) { $registry->register( new CER_Block_Integration() ); } );
    }

    public static function gateway( $id ) {
        if ( ! in_array( $id, self::GATEWAYS, true ) ) { return null; }
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway = isset( $gateways[ $id ] ) ? $gateways[ $id ] : null;
        foreach ( array( 'get_payment_tokens_handler', 'get_order_meta', 'update_order_meta', 'get_customer_id', 'get_environment', 'tokenization_enabled' ) as $method ) {
            if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, $method ) ) ) { return null; }
        }
        return $gateway;
    }

    public static function gateway_allowed( $gateway ) {
        // Permit sandbox configuration tests on staging without changing the
        // WooCommerce Subscriptions duplicate-site renewal protection.
        return $gateway && ( ! self::staging() || 'test' === $gateway->get_environment() );
    }

    public static function allowed_gateways() {
        $ids = array();
        foreach ( self::GATEWAYS as $id ) {
            $gateway = self::gateway( $id );
            if ( $gateway && $gateway->tokenization_enabled() ) { $ids[] = $id; }
        }
        return $ids;
    }

    public static function consent( $order, $request ) {
        $extensions = $request->get_param( 'extensions' );
        $checked = is_array( $extensions ) && isset( $extensions[ self::NS ]['opt_in'] ) && true === $extensions[ self::NS ]['opt_in'];
        $targets = self::cart_targets();
        $gateway = self::gateway( $order->get_payment_method() );
        // Consent is scoped to this checkout attempt and these subscription IDs.
        $order->delete_meta_data( self::CONSENT );
        $order->delete_meta_data( self::TRANSACTION );
        if ( ! $checked ) { return; }
        if ( ! $targets || ! $gateway || ! $gateway->tokenization_enabled()
            || (int) $order->get_user_id() !== get_current_user_id() ) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'cer_opt_in_unavailable', 'Automatic renewal opt-in is unavailable. Choose Authorize.Net credit card or eCheck, or uncheck automatic renewals.', 400
            );
        }
        $order->update_meta_data( self::CONSENT, array(
            'order_id' => $order->get_id(), 'user_id' => $order->get_user_id(),
            'gateway' => $gateway->id, 'targets' => $targets,
            'time' => gmdate( 'c' ), 'label' => self::LABEL, 'details' => self::details(),
        ) );
        $order->save_meta_data();
    }

    private static function consent_record( $order ) {
        $record = $order->get_meta( self::CONSENT );
        return is_array( $record ) && isset( $record['order_id'], $record['user_id'], $record['gateway'], $record['targets'] )
            && (int) $record['order_id'] === (int) $order->get_id()
            && (int) $record['user_id'] === (int) $order->get_user_id()
            && $record['gateway'] === $order->get_payment_method() ? $record : null;
    }

    public static function force_save( $result, $order_id, $gateway ) {
        $order = wc_get_order( $order_id );
        if ( $order && self::consent_record( $order ) && self::gateway_allowed( $gateway ) && in_array( $gateway->id, self::GATEWAYS, true ) ) {
            // SkyVerge's should_tokenize() reads this field. Set it inside the
            // gateway processing hook, after its Blocks adapter maps payment data.
            $_POST[ 'wc-' . str_replace( '_', '-', $gateway->id ) . '-tokenize-payment-method' ] = 'true';
        }
        return $result;
    }

    public static function transaction( $order, $response, $gateway ) {
        if ( ! self::consent_record( $order ) ) { return; }
        // SkyVerge has already persisted the exact token and customer ID used
        // for this transaction before firing this action. Reading those values
        // through its public API works across framework versions and HPOS.
        $token = $gateway->get_order_meta( $order, 'payment_token' );
        $order->update_meta_data( self::TRANSACTION, array(
            'order_id' => $order->get_id(), 'token' => (string) $token,
            'customer_id' => (string) $gateway->get_order_meta( $order, 'customer_id' ),
            'environment' => $gateway->get_environment(),
            'trans_date' => (string) $gateway->get_order_meta( $order, 'trans_date' ),
        ) );
        $order->save_meta_data();
    }

    public static function queue( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! self::consent_record( $order ) || ! $order->is_paid() ) { return; }
        // Apply at shutdown, after the gateway and Subscriptions have finished
        // processing this request. Keep a scheduled action as a fallback for
        // asynchronous gateways and interrupted requests.
        self::$pending_orders[ (int) $order_id ] = (int) $order_id;
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + 15, 'cer_authnet_apply_opt_in', array( (int) $order_id ), 'cer-authnet', true );
        } elseif ( ! wp_next_scheduled( 'cer_authnet_apply_opt_in', array( (int) $order_id ) ) ) {
            wp_schedule_single_event( time() + 15, 'cer_authnet_apply_opt_in', array( (int) $order_id ) );
        }
    }

    public static function flush() {
        foreach ( self::$pending_orders as $order_id ) {
            self::apply( $order_id );
        }
        self::$pending_orders = array();
    }

    public static function email_auto_renew_notice( $order, $sent_to_admin, $plain_text = false ) {
        if ( $sent_to_admin || ! $order instanceof WC_Order || ! function_exists( 'wcs_get_subscription' ) ) { return; }
        $consent = self::consent_record( $order );
        if ( ! $consent || empty( $consent['targets'] ) ) { return; }

        $subscriptions = array();
        foreach ( array_unique( array_map( 'absint', $consent['targets'] ) ) as $id ) {
            $subscription = wcs_get_subscription( $id );
            if ( $subscription instanceof WC_Subscription
                && (int) $subscription->get_user_id() === (int) $order->get_user_id()
                && $subscription->get_time( 'next_payment' ) > 0 ) {
                $subscriptions[] = $subscription;
            }
        }
        if ( ! $subscriptions ) { return; }

        // On a resend the opt-in has already been applied, and the native
        // Subscriptions template has printed this notice at priority 15.
        foreach ( $subscriptions as $subscription ) {
            if ( ! $subscription->is_manual() ) { return; }
        }

        if ( 1 === count( $subscriptions ) ) {
            $account_url = $subscriptions[0]->get_view_order_url();
        } else {
            $account_url = wc_get_endpoint_url( 'subscriptions', '', wc_get_page_permalink( 'myaccount' ) );
        }

        if ( $plain_text ) {
            printf(
                "\n%s\n",
                sprintf(
                    _n(
                        'This subscription is set to renew automatically using your payment method on file. You can manage or cancel this subscription from your my account page: %s',
                        'These subscriptions are set to renew automatically using your payment method on file. You can manage or cancel your subscriptions from your my account page: %s',
                        count( $subscriptions ),
                        'cer-authorize-net'
                    ),
                    esc_url_raw( $account_url )
                )
            );
            return;
        }

        printf(
            '<p style="margin: -32px 0 40px;"><small>%s</small></p>',
            wp_kses_post(
                sprintf(
                    _n(
                        'This subscription is set to renew automatically using your payment method on file. You can manage or cancel this subscription from your %1$smy account page%2$s.',
                        'These subscriptions are set to renew automatically using your payment method on file. You can manage or cancel your subscriptions from your %1$smy account page%2$s.',
                        count( $subscriptions ),
                        'cer-authorize-net'
                    ),
                    '<a href="' . esc_url( $account_url ) . '">',
                    '</a>'
                )
            )
        );
    }

    public static function apply( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->is_paid() || ! function_exists( 'wcs_get_subscriptions_for_order' ) ) { return; }
        $consent = self::consent_record( $order );
        if ( ! $consent ) { return; }
        $gateway = self::gateway( $consent['gateway'] );
        $transaction = $order->get_meta( self::TRANSACTION );
        if ( self::staging() && $gateway && 'production' === $gateway->get_environment() ) {
            if ( ! $order->get_meta( '_cer_authnet_staging_preview' ) ) {
                $order->update_meta_data( '_cer_authnet_staging_preview', 'yes' );
                $order->save_meta_data();
                $order->add_order_note( 'Automatic renewal opt-in selected during staging preview. Authorize.Net is in Production mode, so this plugin did not enable future automatic payments or attach a production payment profile to the subscription.' );
            }
            return;
        }
        try {
            if ( ! self::gateway_allowed( $gateway ) || ! is_array( $transaction ) || empty( $transaction['token'] ) || empty( $transaction['customer_id'] ) || empty( $transaction['trans_date'] )
                || (int) $transaction['order_id'] !== (int) $order_id
                || $transaction['environment'] !== $gateway->get_environment()
                || ! $gateway->get_payment_tokens_handler()->user_has_token( $order->get_user_id(), $transaction['token'] )
                || (string) $gateway->get_customer_id( $order->get_user_id() ) !== $transaction['customer_id'] ) {
                throw new \RuntimeException( 'The renewal transaction did not provide a verified saved payment profile. Automatic renewal was not enabled.' );
            }
            $related = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'renewal' ) );
            $related_by_id = array();
            foreach ( $related as $related_subscription ) {
                if ( $related_subscription instanceof WC_Subscription ) {
                    $related_by_id[ (int) $related_subscription->get_id() ] = $related_subscription;
                }
            }
            $updated = array();
            foreach ( $consent['targets'] as $id ) {
                $id = absint( $id );
                $sub = isset( $related_by_id[ $id ] ) ? $related_by_id[ $id ] : null;
                if ( ! $sub ) {
                    throw new \RuntimeException( sprintf( 'Subscription #%d is not related to renewal order #%d. Automatic renewal was not enabled.', $id, $order_id ) );
                }
                if ( (int) $sub->get_meta( '_cer_authnet_enabled_order' ) === (int) $order_id ) {
                    $updated[] = $id;
                    continue;
                }
                if ( ! self::eligible( $sub, $order->get_user_id() ) ) {
                    throw new \RuntimeException( sprintf( 'Subscription #%d is no longer eligible for automatic renewal opt-in.', $id ) );
                }
                // Use SkyVerge's own subscription meta API. Its Subscriptions
                // integration requires only payment_token and customer_id.
                $gateway->update_order_meta( $sub, 'payment_token', $transaction['token'] );
                $gateway->update_order_meta( $sub, 'customer_id', $transaction['customer_id'] );
                $sub->set_payment_method( $gateway );
                $sub->set_requires_manual_renewal( false );
                $sub->update_meta_data( '_cer_authnet_enabled_order', (int) $order_id );
                $sub->save();
                $updated[] = $id;
                if ( self::staging() ) {
                    $sub->add_order_note( 'Sandbox opt-in test: a test payment profile was attached. WooCommerce staging protection remains enabled; scheduled automatic charges stay disabled.' );
                }
                $sub->add_order_note( sprintf( 'Customer opted into automatic renewals at checkout for renewal order #%d. Authorize.Net payment profile verified and attached.', $order_id ) );
                $order->add_order_note( sprintf( 'Automatic renewals enabled for subscription #%d following the customer checkout opt-in.', $id ) );
            }
            if ( count( $updated ) !== count( array_unique( array_map( 'absint', $consent['targets'] ) ) ) ) {
                throw new \RuntimeException( 'Not every selected subscription was updated. Automatic renewal opt-in will be retried.' );
            }
            if ( $order->get_meta( '_cer_authnet_error' ) ) {
                $order->delete_meta_data( '_cer_authnet_error' );
                $order->save_meta_data();
            }
        } catch ( \Throwable $error ) {
            $message = $error->getMessage();
            if ( $order->get_meta( '_cer_authnet_error' ) !== $message ) {
                $order->update_meta_data( '_cer_authnet_error', $message );
                $order->save_meta_data();
                $order->add_order_note( 'Automatic renewal opt-in: ' . $message );
                wc_get_logger()->error( $message, array( 'source' => 'cer-authnet', 'order_id' => $order_id ) );
            }
        }
    }
}
CER_Authorize_Net::boot();
