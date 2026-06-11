jQuery(function ($) {
    let stripe, elements;
    let cardNumber, cardExpiry, cardCvc, postalCode;

    const style = {
        base: {
            color: '#000',
            fontSize: '16px',
			fontWeight: '700',
            fontFamily: 'inherit',
			backgroundColor: '#f4f4f4',
			lineHeight: '24px',
            '::placeholder': {
                color: '#666'
            }
        },
        invalid: {
            color: '#000'
        }
    };

    function initStripe() {

        if ( typeof Stripe === 'undefined' ) return;
        if ( ! dddStripe.key ) return;

        stripe = Stripe(dddStripe.key);
        elements = stripe.elements();

        cardNumber = elements.create('cardNumber', { style });
        cardExpiry = elements.create('cardExpiry', { style });
        cardCvc    = elements.create('cardCvc', { style });
        postalCode = elements.create('postalCode', {
			placeholder: '12345',
			style
		});

        cardNumber.mount('#stripe-card-number');
        cardExpiry.mount('#stripe-card-expiry');
        cardCvc.mount('#stripe-card-cvc');
        postalCode.mount('#stripe-postal-code');

        cardNumber.on('change', handleError);
        cardExpiry.on('change', handleError);
        cardCvc.on('change', handleError);
        postalCode.on('change', handleError);
    }

    function handleError(event) {
        $('#ddd-stripe-errors').text(
            event.error ? event.error.message : ''
        );
    }

    initStripe();

    $(document.body).on('updated_checkout', function () {
        $('.stripe-field').empty();
        initStripe();
    });

	
	let stripeSubmitting = false;

	$('form.checkout').on('checkout_place_order', function (e) {
	//$(document).on('submit', 'form.checkout, form#order_review', function (e) {
		
		console.log("Stripe script triggered");

		if ($('input[name="payment_method"]:checked').val() !== 'ddd_stripe') {
			return true;
		}

		if (stripeSubmitting) {
			return true;
		}

		e.preventDefault();

		//console.log('Stripe handler running');

		stripe.createPaymentMethod({
			type: 'card',
			card: cardNumber
		}).then(function (result) {
			console.log("Stripe script triggered before error code");
			
			if (result.error) {
				$('#ddd-stripe-errors').text(result.error.message);
				//console.log( "Stripe_payment_method: " + $('input[name="stripe_payment_method"]').val() );
				stripeSubmitting = false;
				return;
			}
			
			console.log("Stripe script triggered after error code");

			$('input[name="stripe_payment_method"]').remove();

			//if( $('form.checkout').length ) {
				$('<input>', {
					type: 'hidden',
					name: 'stripe_payment_method',
					value: result.paymentMethod.id
				}).appendTo('form.checkout');

				stripeSubmitting = true;
				$('form.checkout').submit();
			//}
			
			/*if( $('form#order_review').length ) {
				$('<input>', {
					type: 'hidden',
					name: 'stripe_payment_method',
					value: result.paymentMethod.id
				}).appendTo('form#order_review');

				stripeSubmitting = true;
				$('form#order_review').submit();
			}*/
		});

		return false;
	});
});
