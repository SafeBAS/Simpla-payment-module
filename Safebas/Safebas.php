<?php

require_once('api/Simpla.php');

class Safebas extends Simpla
{	
	private function GetRate() {
		$apikey = $payment_settings['apikey'];
		$url    = 'https://api.etherscan.io/api?module=stats&action=ethprice&apikey=' . $apikey;
		$data   = file_get_contents($url);
		if ( !empty($data)) {
			$contents = json_decode(html_entity_decode($data), TRUE);
			if ($contents['status']==1) {
				$value = $contents['result']['ethusd'];		
				$courseUSD = $this->money->get_currency('USD');
				$courseRUR = $courseUSD->rate_to * $value;
				$courseRUR = sprintf("%.2f", $courseRUR);				
			return $courseRUR;
			}
		}
	}


	public function checkout_form($order_id, $button_text = null)
	{
		if(empty($button_text))
			$button_text = 'Перейти к оплате';
			$order = $this->orders->get_order((int)$order_id);
			$payment_method = $this->payment->get_payment_method($order->payment_method_id);
			$payment_settings = $this->payment->get_payment_settings($payment_method->id);
			$payment_currency = $this->money->get_currency(intval($payment_method->currency_id));
			$settings = $this->payment->get_payment_settings($payment_method->id);
			$price = round($this->money->convert($order->total_price, $payment_method->currency_id, false), 2);
			$courseRUR = $this->GetRate();
			$price = round($price/$courseRUR,8);
			$price = sprintf("%.16f", $price);
			$mode = $payment_settings['mode'];
			$return_url = $this->config->root_url.'/payment/Safebas/callback.php';
			$desc = 'Оплата заказа №'.$order->id;
			$button =	'<form action="https://api.safebas.com/v1/qpay" method="POST"/>'.
						'<input type="hidden" name="receiver" value="'.$settings['receiver'].'" />'.
						'<input type="hidden" name="amount" value="'.$price.'" />'.					
						'<input type="hidden" name="description" value="'.$desc.'" />'.
						'<input type="hidden" name="mode" value="'.$mode.'" />'.
						'<input type="hidden" name="pInt" value="'.$order->id.'" />'.
						'<input type="hidden" name="pStr" value="'.$courseRUR.'" />'.
						'<input type="hidden" name="returnSuccessUrl" value="'.$return_url.'" />'.
						'<input type=submit class=checkout_button value="'.$button_text.'">'.
						'</form>';
		return $button;
	}
}