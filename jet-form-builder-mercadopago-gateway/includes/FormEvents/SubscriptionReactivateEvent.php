<?php


namespace Jet_FB_Mercadopago_Gateway\FormEvents;


class SubscriptionReactivateEvent extends BaseSubscriptionEvent {

	public function get_id(): string {
		return 'MERCADOPAGO.SUBSCRIPTION.REACTIVATE';
	}

	public function get_help(): string {
		return __(
			"is executed when a subscription is being activated. 
			Reactivation solely applies to the existing subscription and can be 
			carried out through a personal MERCADOPAGO business account, 
			namely, its <b><i>Pay & Get Paid > Subscriptions</i></b> section.",
			'jet-form-builder-paypal-subscriptions'
		);
	}

}