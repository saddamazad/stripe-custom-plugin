<?php
//add_action( 'woocommerce_order_refunded', 'ddd_stripe_process_refund', 10, 2 );
function ddd_stripe_process_refund( $order_id, $refund_id ) {
    $order  = wc_get_order( $order_id );
    $refund = wc_get_order( $refund_id );

    // Only for DDD Stripe Gateway
    if ( $order->get_payment_method() !== 'ddd_stripe' ) {
        return;
    }

    $charge_id = $order->get_transaction_id();

    if ( ! $charge_id ) {
        return;
    }

	$ddd_stripe_options = get_option('woocommerce_ddd_stripe_settings');
	
	$stripe_secret_key = $ddd_stripe_options['live_secret_key'];
	
	if( $ddd_stripe_options['testmode'] == "yes" ) {
		$stripe_secret_key = $ddd_stripe_options['test_secret_key'];
	}
	
	\Stripe\Stripe::setApiKey( $stripe_secret_key );

    try {
        \Stripe\Refund::create([
            'charge' => $charge_id,
            'amount' => wc_stripe_amount( $refund->get_amount(), $order->get_currency() ),
            'metadata' => [
                'order_id'  => $order_id,
                'refund_id' => $refund_id,
            ],
        ]);

        // Order note (Stripe-style)
        $order->add_order_note(
            sprintf(
                'Stripe refund created (Charge ID: %s, Amount: %s)',
                $charge_id,
                wc_price( $refund->get_amount(), [ 'currency' => $order->get_currency() ] )
            )
        );
    } catch ( \Stripe\Exception\ApiErrorException $e ) {
        $order->add_order_note(
            sprintf(
                'Stripe refund failed: %s',
                $e->getMessage()
            )
        );
    }
}

function wc_stripe_amount( $amount, $currency ) {
    return (int) round( $amount * 100 );
}

//add_action( 'woocommerce_order_refunded', 'ddd_ld_remove_course_on_refund', 10, 2 );
function ddd_ld_remove_course_on_refund( $order_id, $refund_id ) {
    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        return;
    }

    // Only full refunds, unenroll only when fully refunded
    if ( ! $order->has_status( 'refunded' ) ) {
        return;
    }

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
            ld_update_course_access( $user_id, $course_id, true );

            // Remove progress if we want the user to start fresh
            //ld_delete_course_progress( $course_id, $user_id );

            /*$order->add_order_note(
                sprintf(
                    'LearnDash: user unenrolled from course ID %d due to refund.',
                    $course_id
                )
            );*/
        }
    }
}

function ddd_get_learndash_courses_from_product( $product_id ) {

    $courses = get_post_meta( $product_id, '_related_course', true );

    if ( empty( $courses ) ) {
        return [];
    }

    // Normalize to array
    if ( ! is_array( $courses ) ) {
        $courses = [ $courses ];
    }

    return array_map( 'intval', $courses );
}

function user_still_owns_course( $user_id, $course_id, $exclude_order_id ) {
    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'status'      => array('completed'),
        'limit'       => -1,
    ));

    foreach ( $orders as $order ) {
        if ( $order->get_id() == $exclude_order_id ) {
            continue;
        }

        if ( $order->get_status() === 'refunded' ) {
            continue;
        }

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $course_ids = ddd_get_learndash_courses_from_product( $product_id );

            if ( in_array( $course_id, $course_ids ) ) {
                return true;
            }
        }
    }

    return false;
}

add_action( 'woocommerce_order_status_refunded', 'ddd_fix_learndash_access_after_refund', 999 );
function ddd_fix_learndash_access_after_refund( $order_id ) {
    $order   = wc_get_order( $order_id );
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
            // If course completed → always restore access
            /*if ( learndash_course_completed( $user_id, $course_id ) ) {
                ld_update_course_access( $user_id, $course_id );
                continue;
            }*/

            // If user still owns via another order → restore access
            if ( user_still_owns_course( $user_id, $course_id, $order_id ) ) {
                ld_update_course_access( $user_id, $course_id );
            }
        }
    }
}

function should_remove_course_access($user_id, $course_id, $exclude_order_id) {
    // If course is completed → NEVER remove access
    if ( learndash_course_completed($user_id, $course_id) ) {
        return false;
    }

    // Check for another completed order containing this course
    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'status'      => array('completed'),
        'limit'       => -1,
    ));

    foreach ($orders as $order) {

        if ($order->get_id() == $exclude_order_id) {
            continue; // skip refunded order
        }

        foreach ($order->get_items() as $item) {

            $product_id = $item->get_product_id();
            $linked_course_id = get_post_meta($product_id, '_related_course', true);

            if ($linked_course_id == $course_id) {
                return false; // Another valid paid order exists
            }
        }
    }

    // No other paid order and course not completed
    return true;
}
