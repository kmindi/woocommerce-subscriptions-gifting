<?php

class WCSG_Email {

	/**
	 * Setup hooks & filters, when the class is initialised.
	 */
	public static function init() {

		add_filter( 'woocommerce_email_classes', __CLASS__ . '::add_new_recipient_customer_email', 11, 1 );

		add_action( 'woocommerce_init', __CLASS__ . '::hook_email' );
	}

	/**
	 * Add WCS Gifting email classes.
	 */
	public static function add_new_recipient_customer_email( $email_classes ) {

		require_once( 'emails/class-wcsg-email-customer-new-account.php' );
		require_once( 'emails/class-wcsg-email-completed-renewal-order.php' );
		require_once( 'emails/class-wcsg-email-processing-renewal-order.php' );
		require_once( 'emails/class-wcsg-email-recipient-processing-order.php' );

		$email_classes['WCSG_Email_Customer_New_Account'] = new WCSG_Email_Customer_New_Account();
		$email_classes['WCSG_Email_Completed_Renewal_Order'] = new WCSG_Email_Completed_Renewal_Order();
		$email_classes['WCSG_Email_Processing_Renewal_Order'] = new WCSG_Email_Processing_Renewal_Order();
		$email_classes['WCSG_Email_Recipient_Processing_Order'] = new WCSG_Email_Recipient_Processing_Order();

		return $email_classes;
	}

	/**
	 * Hooks up all of WCS Gifting emails after the WooCommerce object is constructed.
	 */
	public static function hook_email() {

		add_action( 'woocommerce_created_customer', __CLASS__ . '::maybe_remove_wc_new_customer_email', 9, 2 );
		add_action( 'woocommerce_created_customer', __CLASS__ . '::send_new_recient_user_email', 10, 3 );
		add_action( 'woocommerce_created_customer', __CLASS__ . '::maybe_reattach_wc_new_customer_email', 11, 2 );

		add_action( 'woocommerce_order_status_pending_to_processing', __CLASS__ . '::maybe_send_recipient_order_emails', 9, 1 );
		add_action( 'woocommerce_order_status_pending_to_on-hold', __CLASS__ . '::maybe_send_recipient_order_emails', 9, 1 );

		$renewal_notification_actions = array(
			'woocommerce_order_status_pending_to_processing_renewal_notification',
			'woocommerce_order_status_pending_to_on-hold_renewal_notification',
			'woocommerce_order_status_completed_renewal_notification',
		);
		foreach ( $renewal_notification_actions as $action ) {
			add_action( $action , __CLASS__ . '::maybe_send_recipient_renewal_notification', 12, 1 );
		}

		$mailer              = WC()->mailer();
		$subscription_emails = WC_Subscriptions_Email::add_emails( array() );

		foreach( $subscription_emails as $key => $email ) {
			$subscription_emails[ $key ] = $email->id;
		}

		foreach ( $mailer->emails as $email ) {

			$filter_prefix = ( in_array( $email->id , $subscription_emails ) ) ? 'woocommerce_subscriptions' : 'woocommerce';

			if ( isset( $email->subject_downloadable ) ) {
				add_filter( $filter_prefix . '_email_subject_' . $email->id, __CLASS__ . '::maybe_change_download_email_subject', 10, 2 );
			}

			if ( isset( $email->heading_downloadable ) ) {
				add_filter( $filter_prefix . '_email_heading_' . $email->id, __CLASS__ . '::maybe_change_download_email_heading', 10, 2 );
			}
		}
	}

	/**
	 * If an order contains subscriptions with recipient data send an email to the recipient
	 * notifying them on their new subscription(s)
	 *
	 * @param int $order_id
	 */
	public static function maybe_send_recipient_order_emails( $order_id ) {
		$subscriptions = wcs_get_subscriptions( array( 'order_id' => $order_id ) );
		$processed_recipients = array();
		if ( ! empty( $subscriptions ) ) {
			WC()->mailer();
			foreach ( $subscriptions as $subscription ) {
				if ( isset( $subscription->recipient_user ) ) {
					if ( ! in_array( $subscription->recipient_user, $processed_recipients ) ) {
						$recipient_subscriptions = WCSG_Recipient_Management::get_recipient_subscriptions( $subscription->recipient_user, $order_id );
						do_action( 'wcsg_processing_order_recipient_notification', $subscription->recipient_user, $recipient_subscriptions );
						array_push( $processed_recipients, $subscription->recipient_user );
					}
				}
			}
		}
	}

	/**
	 * If a cart item contains recipient data matching the new customer, dont send the core WooCommerce new customer email.
	 *
	 * @param int $customer_id The ID of the new customer being created
	 * @param array $new_customer_data
	 */
	public static function maybe_remove_wc_new_customer_email( $customer_id, $new_customer_data ) {

		foreach ( WC()->cart->cart_contents as $key => $item ) {
			if ( ! empty( $item['wcsg_gift_recipients_email'] ) ) {
				if ( $item['wcsg_gift_recipients_email'] == $new_customer_data['user_email'] ) {
					remove_action( current_filter(), array( 'WC_Emails', 'send_transactional_email' ) );
					break;
				}
			}
		}
	}

	/**
	 * If a cart item contains recipient data matching the new customer, reattach the core WooCommerce new customer email.
	 *
	 * @param int $customer_id The ID of the new customer being created
	 * @param array $new_customer_data
	 */
	public static function maybe_reattach_wc_new_customer_email( $customer_id, $new_customer_data ) {

		foreach ( WC()->cart->cart_contents as $key => $item ) {
			if ( ! empty( $item['wcsg_gift_recipients_email'] ) ) {
				if ( $item['wcsg_gift_recipients_email'] == $new_customer_data['user_email'] ) {
					add_action( current_filter(), array( 'WC_Emails', 'send_transactional_email' ) );
					break;
				}
			}
		}
	}

	/**
	 * If a cart item contains recipient data matching the new customer, init the mailer and call the notification for new recipient customers.
	 *
	 * @param int $customer_id The ID of the new customer being created
	 * @param array $new_customer_data
	 * @param bool $password_generated Whether the password has been generated for the customer
	 */
	public static function send_new_recient_user_email( $customer_id, $new_customer_data, $password_generated ) {
		foreach ( WC()->cart->cart_contents as $key => $item ) {
			if ( isset( $item['wcsg_gift_recipients_email'] ) ) {
				if ( $item['wcsg_gift_recipients_email'] == $new_customer_data['user_email'] ) {
					WC()->mailer();
					$user_password = $new_customer_data['user_pass'];
					$current_user = wp_get_current_user();
					$subscription_purchaser = WCS_Gifting::get_user_display_name( $current_user->ID );
					do_action( 'wcsg_created_customer_notification', $customer_id, $user_password, $subscription_purchaser );
					break;
				}
			}
		}
	}

	/**
	 * If the order contains a subscription that is being gifted, init the mailer and call the notification for recipient renewal notices.
	 *
	 * @param int $order_id The ID of the renewal order with a new status of processing/completed
	 */
	public static function maybe_send_recipient_renewal_notification( $order_id ) {
		$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
		$subscription  = reset( $subscriptions );
		$recipient_id  = get_post_meta( $subscription->id, '_recipient_user', true );
		if ( ! empty( $recipient_id ) ) {
			WC()->mailer();
			do_action( current_filter() . '_recipient', $order_id );
		}
	}

	/**
	 * If an order purchaser doesn't receive download permissions revert the email subject back to it's default subject.
	 *
	 * @param string $subject The email subject.
	 * @param object $order
	 * @return string $subject
	 */
	public static function maybe_change_download_email_subject( $subject, $order ) {

		if ( $order instanceof WC_Order && wcs_order_contains_subscription( $order->id, 'any' ) ) {
			$filter_prefix   = ( false === strpos( current_filter(), 'woocommerce_subscriptions_email' ) ) ? 'woocommerce' : 'woocommerce_subscriptions';
			$email_id        = substr( current_filter(), strlen( $filter_prefix . '_email_subject_' ) );
			$email           = self::get_email_from_id( $email_id );
			$order_downloads = WCSG_Download_Handler::get_user_downloads_for_order( $order, $order->customer_user );

			if ( $email && empty( $order_downloads ) && isset( $email->subject ) ) {
				$subject = $email->format_string( $email->subject );
			}
		}
		return $subject;
	}

	/**
	 * If an order purchaser doesn't receive download permissions revert the email heading back to it's default heading.
	 *
	 * @param string $heading The email heading.
	 * @param object $order
	 * @return string $heading
	 */
	public static function maybe_change_download_email_heading( $heading, $order ) {

		if ( $order instanceof WC_Order && wcs_order_contains_subscription( $order->id, 'any' ) ) {

			$filter_prefix   = ( false === strpos( current_filter(), 'woocommerce_subscriptions_email' ) ) ? 'woocommerce' : 'woocommerce_subscriptions';
			$email_id        = substr( current_filter(), strlen( $filter_prefix . '_email_heading_' ) );
			$email           = self::get_email_from_id( $email_id );
			$order_downloads = WCSG_Download_Handler::get_user_downloads_for_order( $order, $order->customer_user );

			if ( $email && empty( $order_downloads ) && isset( $email->heading ) ) {
				$heading = $email->format_string( $email->heading );
			}
		}

		return $heading;
	}

	/**
	 * Retrieves an email object from its id, otherwise returns false.
	 *
	 * @param int $email_id
	 * @param object $email
	 */
	public static function get_email_from_id( $email_id ) {
		$mailer = WC()->mailer();

		foreach ( $mailer->emails as $email ) {
			if ( $email_id == $email->id ) {
				return $email;
			}
		}

		return false;
	}
}
WCSG_Email::init();
