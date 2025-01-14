<?php
/**
 * These tests make assertions against class WC_Stripe_Webhook_State.
 *
 * @package WooCommerce_Stripe/Tests/Webhook_State
 */

/**
 * WC_Stripe_Webhook_State_Test class.
 */
class WC_Stripe_Webhook_Handler_Test extends WP_UnitTestCase {

	/**
	 * The webhook handler instance for testing.
	 *
	 * @var WC_Stripe_Webhook_Handler
	 */
	private $mock_webhook_handler;

	/**
	 * Mock card payment intent template.
	 */
	const MOCK_PAYMENT_INTENT = [
		'id'      => 'pi_mock',
		'object'  => 'payment_intent',
		'status'  => WC_Stripe_Intent_Status::SUCCEEDED,
		'charges' => [
			'total_count' => 1,
			'data'        => [
				[
					'id'                     => 'ch_mock',
					'captured'               => true,
					'payment_method_details' => [],
					'status'                 => 'succeeded',
				],
			],
		],
	];

	/**
	 * Set up the test.
	 */
	public function set_up() {
		parent::set_up();
		$this->mock_webhook_handler();
	}

	/**
	 * Mock the webhook handler.
	 */
	private function mock_webhook_handler( $exclude_methods = [] ) {
		$methods = [
			'handle_deferred_payment_intent_succeeded',
			'get_intent_from_order',
			'get_latest_charge_from_intent',
			'process_response',
		];

		$methods = array_diff( $methods, $exclude_methods );

		$this->mock_webhook_handler = $this->getMockBuilder( WC_Stripe_Webhook_Handler::class )
			->setMethods( $methods )
			->getMock();
	}

	/**
	 * Test process_deferred_webhook with unsupported webhook type.
	 */
	public function test_process_deferred_webhook_invalid_type() {
		$this->mock_webhook_handler->expects( $this->never() )
			->method( 'handle_deferred_payment_intent_succeeded' );

		$this->expectExceptionMessage( 'Unsupported webhook type: event-id' );
		$this->mock_webhook_handler->process_deferred_webhook( 'event-id', [] );
	}

	/**
	 * Test process_deferred_webhook with invalid args.
	 */
	public function test_process_deferred_webhook_invalid_args() {
		$this->mock_webhook_handler->expects( $this->never() )
			->method( 'handle_deferred_payment_intent_succeeded' );

		// No data.
		$data = [];

		$this->expectExceptionMessage( "Missing required data. 'order_id' is invalid or not found for the deferred 'payment_intent.succeeded' event." );
		$this->mock_webhook_handler->process_deferred_webhook( 'payment_intent.succeeded', $data );

		// Invalid order_id.
		$data = [
			'order_id' => 9999,
		];

		$this->expectExceptionMessage( "Missing required data. 'order_id' is invalid or not found for the deferred 'payment_intent.succeeded' event." );
		$this->mock_webhook_handler->process_deferred_webhook( 'payment_intent.succeeded', $data );

		// No payment intent.
		$order            = WC_Helper_Order::create_order();
		$data['order_id'] = $order->get_id();

		$this->expectExceptionMessage( "Missing required data. 'intent_id' is missing for the deferred 'payment_intent.succeeded' event." );
		$this->mock_webhook_handler->process_deferred_webhook( 'payment_intent.succeeded', $data );
	}

	/**
	 * Test process_deferred_webhook with valid args.
	 */
	public function test_process_deferred_webhook() {
		$order     = WC_Helper_Order::create_order();
		$intent_id = 'pi_mock_1234';
		$data      = [
			'order_id'  => $order->get_id(),
			'intent_id' => $intent_id,
		];

		$this->mock_webhook_handler->expects( $this->once() )
			->method( 'handle_deferred_payment_intent_succeeded' )
			->with(
				$this->callback(
					function( $passed_order ) use ( $order ) {
						return $passed_order instanceof WC_Order && $order->get_id() === $passed_order->get_id();
					}
				),
				$this->equalTo( $intent_id ),
			);

		$this->mock_webhook_handler->process_deferred_webhook( 'payment_intent.succeeded', $data );
	}

	/**
	 * Test deferred webhook where the intent is no longer stored on the order.
	 */
	public function test_mismatch_intent_id_process_deferred_webhook() {
		$order = WC_Helper_Order::create_order();
		$data  = [
			'order_id'  => $order->get_id(),
			'intent_id' => 'pi_wrong_id',
		];

		$this->mock_webhook_handler( [ 'handle_deferred_payment_intent_succeeded' ] );

		// Mock the get intent from order to return the mock intent.
		$this->mock_webhook_handler->expects( $this->once() )
			->method( 'get_intent_from_order' )
			->with(
				$this->callback(
					function( $passed_order ) use ( $order ) {
						return $passed_order instanceof WC_Order && $order->get_id() === $passed_order->get_id();
					}
				)
			)->willReturn( (object) self::MOCK_PAYMENT_INTENT );

		// Expect the get latest charge from intent to be called.
		$this->mock_webhook_handler->expects( $this->never() )
			->method( 'get_latest_charge_from_intent' );

		// Expect the process response to be called with the charge and order.
		$this->mock_webhook_handler->expects( $this->never() )
			->method( 'process_response' );

		$this->mock_webhook_handler->process_deferred_webhook( 'payment_intent.succeeded', $data );
	}

	/**
	 * Test successful deferred webhook.
	 */
	public function test_process_of_successful_payment_intent_deferred_webhook() {
		$order = WC_Helper_Order::create_order();
		$data  = [
			'order_id'  => $order->get_id(),
			'intent_id' => self::MOCK_PAYMENT_INTENT['id'],
		];

		$this->mock_webhook_handler( [ 'handle_deferred_payment_intent_succeeded' ] );

		// Mock the get intent from order to return the mock intent.
		$this->mock_webhook_handler->expects( $this->once() )
			->method( 'get_intent_from_order' )
			->willReturn( (object) self::MOCK_PAYMENT_INTENT );

		// Expect the get latest charge from intent to be called.
		$this->mock_webhook_handler->expects( $this->once() )
			->method( 'get_latest_charge_from_intent' )
			->willReturn( self::MOCK_PAYMENT_INTENT['charges']['data'][0] );

		// Expect the process response to be called with the charge and order.
		$this->mock_webhook_handler->expects( $this->once() )
			->method( 'process_response' )
			->with(
				self::MOCK_PAYMENT_INTENT['charges']['data'][0],
				$this->callback(
					function( $passed_order ) use ( $order ) {
						return $passed_order instanceof WC_Order && $order->get_id() === $passed_order->get_id();
					}
				)
			);

		$this->mock_webhook_handler->process_deferred_webhook( 'payment_intent.succeeded', $data );
	}

	/**
	 * Test for `process_webhook_charge_failed`.
	 *
	 * @param string $order_status       The order status.
	 * @param bool   $order_status_final Whether the order status is final.
	 * @param string $charge_id          The charge ID.
	 * @param array  $event              The event type.
	 * @param string $expected_status    The expected order status.
	 * @param string $expected_note      The expected order note.
	 * @return void
	 * @dataProvider provide_test_process_webhook_charge_failed
	 */
	public function test_process_webhook_charge_failed(
		$order_status,
		$order_status_final,
		$charge_id,
		$event,
		$expected_status,
		$expected_note
	) {
		$order = WC_Helper_Order::create_order();
		$order->set_status( $order_status );
		$order->set_transaction_id( $charge_id );
		if ( $order_status_final ) {
			$order->update_meta_data( '_stripe_status_final', true );
		}
		$order->save();

		$notification = (object) [
			'type' => $event,
			'data' => (object) [
				'object' => (object) [
					'id' => 'ch_fQpkNKxmUrZ8t4CT7EHGS3Rg',
				],
			],
		];

		$this->mock_webhook_handler->process_webhook_charge_failed( $notification );

		if ( $charge_id ) { // Order not found charge ID.
			$final_order = wc_get_order( $order->get_id() );
			$this->assertEquals( $expected_status, $final_order->get_status() );

			if ( $expected_note ) {
				$notes = wc_get_order_notes(
					[
						'order_id' => $final_order->get_id(),
						'limit'    => 1,
					]
				);
				$this->assertSame( $expected_note, $notes[0]->content );
			}
		}
	}

	/**
	 * Provider for `test_process_webhook_charge_failed`.
	 *
	 * @return array
	 */
	public function provide_test_process_webhook_charge_failed() {
		return [
			'order already failed' => [
				'order status'       => 'failed',
				'order status final' => false,
				'charge id'          => 'ch_fQpkNKxmUrZ8t4CT7EHGS3Rg',
				'event'              => 'charge.failed',
				'expected status'    => 'failed',
				'expected note'      => '',
			],
			'charge failed event, order already with the final status' => [
				'order status'       => 'on-hold',
				'order status final' => true,
				'charge id'          => 'ch_fQpkNKxmUrZ8t4CT7EHGS3Rg',
				'event'              => 'charge.failed',
				'expected status'    => 'on-hold',
				'expected note'      => 'This payment failed to clear.',
			],
			'charge failed event'  => [
				'order status'       => 'on-hold',
				'order status final' => false,
				'charge id'          => 'ch_fQpkNKxmUrZ8t4CT7EHGS3Rg',
				'event'              => 'charge.failed',
				'expected status'    => 'failed',
				'expected note'      => 'This payment failed to clear. Order status changed from On hold to Failed.',
			],
			'charge expired event' => [
				'order status'       => 'on-hold',
				'order status final' => false,
				'charge id'          => 'ch_fQpkNKxmUrZ8t4CT7EHGS3Rg',
				'event'              => 'charge.expired',
				'expected status'    => 'failed',
				'expected note'      => 'This payment has expired. Order status changed from On hold to Failed.',
			],
		];
	}

	/**
	 * Test for `process_webhook_dispute`.
	 *
	 * @param bool $order_status_final Whether the order status is final.
	 * @param string $dispute_status   The dispute status.
	 * @param string $expected_status  The expected order status.
	 * @param string $expected_note    The expected order note.
	 * @return void
	 * @dataProvider provide_test_process_webhook_dispute
	 */
	public function test_process_webhook_dispute( $order_status, $order_status_final, $dispute_status, $expected_status, $expected_note ) {
		$charge_id = 'ch_fQpkNKxmUrZ8t4CT7EHGS3Rg';

		$order = WC_Helper_Order::create_order();
		$order->set_status( $order_status );
		$order->set_transaction_id( $charge_id );
		if ( $order_status_final ) {
			$order->update_meta_data( '_stripe_status_final', true );
		}
		$order->save();

		$notification = (object) [
			'type' => 'charge.dispute.created',
			'data' => (object) [
				'object' => (object) [
					'charge' => $charge_id,
					'status' => $dispute_status,
				],
			],
		];

		$this->mock_webhook_handler->process_webhook_dispute( $notification );

		$final_order = wc_get_order( $order->get_id() );

		$notes = wc_get_order_notes(
			[
				'order_id' => $final_order->get_id(),
				'limit'    => 1,
			]
		);

		$this->assertSame( $expected_status, $final_order->get_status() );
		$this->assertMatchesRegularExpression( $expected_note, $notes[0]->content );

	}

	/**
	 * Provider for `test_process_webhook_dispute`.
	 *
	 * @return array
	 */
	public function provide_test_process_webhook_dispute() {
		return [
			'response needed, order status not final'     => [
				'order status'       => 'processing',
				'order status final' => false,
				'dispute status'     => 'needs_response',
				'expected status'    => 'on-hold',
				'expected note'      => '/A dispute was created for this order. Response is needed./',
			],
			'response needed, order status not final, status is cancelled' => [
				'order status'       => 'cancelled',
				'order status final' => false,
				'dispute status'     => 'needs_response',
				'expected status'    => 'cancelled',
				'expected note'      => '/A dispute was created for this order. Response is needed./',
			],
			'response needed, order status final'         => [
				'order status'       => 'processing',
				'order status final' => true,
				'dispute status'     => 'needs_response',
				'expected status'    => 'processing',
				'expected note'      => '/A dispute was created for this order. Response is needed./',
			],
			'response not needed, order status not final' => [
				'order status'       => 'processing',
				'order status final' => false,
				'dispute status'     => 'lost',
				'expected status'    => 'on-hold',
				'expected note'      => '/A dispute was created for this order. Order status changed from Processing to On hold./',
			],
		];
	}

	/**
	 * Test for `process_payment_intent`.
	 *
	 * @param string $event_type The event type.
	 * @param string $order_status The order status.
	 * @param bool $order_locked Whether the order is locked.
	 * @param string $payment_type The payment method.
	 * @param bool $order_status_final Whether the order status is final.
	 * @param string $expected_status The expected order status.
	 * @param string $expected_note The expected order note.
	 * @param int $expected_process_payment_calls The expected number of calls to process_payment.
	 * @param int $expected_process_payment_intent_incomplete_calls The expected number of calls to process_payment_intent_incomplete.
	 * @return void
	 * @dataProvider provide_test_process_payment_intent
	 * @throws WC_Data_Exception When order status is invalid.
	 */
	public function test_process_payment_intent(
		$event_type,
		$order_status,
		$order_locked,
		$payment_type,
		$order_status_final,
		$expected_status,
		$expected_note,
		$expected_process_payment_calls,
		$expected_process_payment_intent_incomplete_calls
	) {
		$mock_action_process_payment = new MockAction();
		add_action(
			'wc_gateway_stripe_process_payment',
			[ &$mock_action_process_payment, 'action' ]
		);

		$mock_action_process_payment_intent_incomplete = new MockAction();
		add_action(
			'wc_gateway_stripe_process_payment_intent_incomplete',
			[ &$mock_action_process_payment_intent_incomplete, 'action' ]
		);

		$order = WC_Helper_Order::create_order();
		$order->set_status( $order_status );
		if ( $order_locked ) {
			$order->update_meta_data( '_stripe_lock_payment', ( time() + MINUTE_IN_SECONDS ) );
		}
		if ( $order_status_final ) {
			$order->update_meta_data( '_stripe_status_final', true );
		}
		$order->update_meta_data( '_stripe_upe_payment_type', $payment_type );
		$order->update_meta_data( '_stripe_upe_waiting_for_redirect', true );
		$order->save_meta_data();
		$order->save();

		$notification = [
			'type' => $event_type,
			'data' => [
				'object' => [
					'id'                 => 'pi_mock',
					'charges'            => [
						[
							'metadata' => [
								'order_id' => $order->get_id(),
							],
						],
					],
					'last_payment_error' => [
						'message' => 'Your card was declined. You can call your bank for details.',
					],
				],
			],
		];

		$notification = json_decode( wp_json_encode( $notification ) );

		$this->mock_webhook_handler->process_payment_intent( $notification );

		$final_order = wc_get_order( $order->get_id() );

		$this->assertSame( $expected_status, $final_order->get_status() );
		if ( ! empty( $expected_note ) ) {
			$notes = wc_get_order_notes(
				[
					'order_id' => $final_order->get_id(),
					'limit'    => 1,
				]
			);
			$this->assertMatchesRegularExpression( $expected_note, $notes[0]->content );
		}

		$this->assertEquals( $expected_process_payment_calls, $mock_action_process_payment->get_call_count() );
		$this->assertEquals( $expected_process_payment_intent_incomplete_calls, $mock_action_process_payment_intent_incomplete->get_call_count() );
	}

	/**
	 * Provider for `test_process_payment_intent`.
	 *
	 * @return array
	 */
	public function provide_test_process_payment_intent() {
		return [
			'invalid status'                              => [
				'event type'                     => 'payment_intent.succeeded',
				'order status'                   => 'cancelled',
				'order locked'                   => false,
				'payment type'                   => WC_Stripe_Payment_Methods::CARD,
				'order status final'             => false,
				'expected status'                => 'cancelled',
				'expected note'                  => '',
				'expected process payment calls' => 0,
				'expected process payment intent incomplete calls' => 0,
			],
			'order is locked'                             => [
				'event type'                     => 'payment_intent.succeeded',
				'order status'                   => 'pending',
				'order locked'                   => true,
				'payment type'                   => WC_Stripe_Payment_Methods::CARD,
				'order status final'             => false,
				'expected status'                => 'pending',
				'expected note'                  => '',
				'expected process payment calls' => 0,
				'expected process payment intent incomplete calls' => 0,
			],
			'success, payment_intent.requires_action, voucher payment' => [
				'event type'                     => 'payment_intent.requires_action',
				'order status'                   => 'pending',
				'order locked'                   => false,
				'payment type'                   => WC_Stripe_Payment_Methods::BOLETO,
				'order status final'             => false,
				'expected status'                => 'on-hold',
				'expected note'                  => '/Awaiting payment. Order status changed from Pending payment to On hold./',
				'expected process payment calls' => 0,
				'expected process payment intent incomplete calls' => 0,
			],
			'success, payment_intent.succeeded, voucher payment' => [
				'event type'                     => 'payment_intent.succeeded',
				'order status'                   => 'pending',
				'order locked'                   => false,
				'payment type'                   => WC_Stripe_Payment_Methods::BOLETO,
				'order status final'             => false,
				'expected status'                => 'pending',
				'expected note'                  => '',
				'expected process payment calls' => 1,
				'expected process payment intent incomplete calls' => 0,
			],
			'success, payment_intent.amount_capturable_updated, async payment, awaiting action' => [
				'event type'                     => 'payment_intent.amount_capturable_updated',
				'order status'                   => 'pending',
				'order locked'                   => false,
				'payment type'                   => WC_Stripe_Payment_Methods::CARD,
				'order status final'             => false,
				'expected status'                => 'pending',
				'expected note'                  => '',
				'expected process payment calls' => 0,
				'expected process payment intent incomplete calls' => 1,
			],
			'success, payment_intent.payment_failed, voucher payment' => [
				'event type'                     => 'payment_intent.payment_failed',
				'order status'                   => 'pending',
				'order locked'                   => false,
				'payment type'                   => WC_Stripe_Payment_Methods::BOLETO,
				'order status final'             => false,
				'expected status'                => 'failed',
				'expected note'                  => '/Payment not completed in time Order status changed from Pending payment to Failed./',
				'expected process payment calls' => 0,
				'expected process payment intent incomplete calls' => 0,
			],
			'success, payment_intent.payment_failed, IPP' => [
				'event type'                     => 'payment_intent.payment_failed',
				'order status'                   => 'pending',
				'order locked'                   => false,
				'payment type'                   => WC_Stripe_Payment_Methods::CARD_PRESENT,
				'order status final'             => false,
				'expected status'                => 'failed',
				'expected note'                  => '/Stripe SCA authentication failed. Reason: Your card was declined. You can call your bank for details. Order status changed from Pending payment to Failed./',
				'expected process payment calls' => 0,
				'expected process payment intent incomplete calls' => 0,
			],
			'success, payment_intent.payment_failed, IPP, status final' => [
				'event type'                     => 'payment_intent.payment_failed',
				'order status'                   => 'pending',
				'order locked'                   => false,
				'payment type'                   => WC_Stripe_Payment_Methods::CARD_PRESENT,
				'order status final'             => true,
				'expected status'                => 'pending',
				'expected note'                  => '/Stripe SCA authentication failed. Reason: Your card was declined. You can call your bank for details./',
				'expected process payment calls' => 0,
				'expected process payment intent incomplete calls' => 0,
			],
		];
	}
}
