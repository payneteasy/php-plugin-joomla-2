<?php

defined('_JEXEC') or die;

require_once VMPATH_PLUGINLIBS .'/vmpsplugin.php';
include_once 'Payneteasy.lib.php';

use Payneteasy\PneApi;
use Payneteasy\PneConfig;
use Payneteasy\PneUnknownResultException;

class plgVmPaymentPayneteasy extends vmPSPlugin {
	private static PneApi $Api;

	public function __construct(&$subject, $config) {
		parent::__construct($subject, $config);

		[ $this->_tablepkey, $this->_tableId, $this->tableFields ] = [ 'id', 'id', array_keys($this->getTableSQLFields()) ];

		$varsToPush = $this->getVarsToPush();
		$this->addVarsToPushCore($varsToPush, 1);
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	public function getTableSQLFields() {
		return [
			'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'INT UNSIGNED',
			'virtuemart_paymentmethod_id' => 'INT UNSIGNED',
			'payment_name' => 'VARCHAR(16)',
			'payneteasy_order_id' => 'VARCHAR(48)',
			'payneteasy_status' => 'VARCHAR(48)',
			'payneteasy_descriptor' => 'VARCHAR(128)' ];
	}

	private static function _T(string $arg): string
		{ return JText::_('PAYNETEASY_'.strtoupper($arg)); }

	private static function Api($Method): PneApi
		{ return self::$Api ??= new PneApi( PneConfig::fetchkey_only(fn($k) => strpos($k, 'IS_') === 0 ? (bool)($Method->$k ?? null) : ($Method->$k ?? null)) ); }

	private function returnUrl(stdClass $Details): string {
		return JRoute::_(JURI::root() .'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived'
			."&orderId={$Details->virtuemart_order_id}&methodId={$Details->virtuemart_paymentmethod_id}"
			.'&key='.self::returnKey((int)$Details->virtuemart_order_id, (string)$Details->order_number));
	}

	// The return address is known to the customer and the gateway only
	private static function returnKey(int $orderId, string $order_number): string
		{ return substr(hash_hmac('sha256', "payneteasy|$orderId|$order_number", (string)JFactory::getApplication()->get('secret')), 0, 32); }

	// Non-scalar request values (key[]=x) are ignored
	private static function reqStr(string $name): string
		{ return is_scalar($v = vRequest::getString($name, '')) ? (string)$v : ''; }

	private static function reqInt(string $name): int
		{ return is_scalar($v = vRequest::getInt($name, 0)) ? (int)$v : 0; }

	// One row per payment attempt, kept by order number: with "reuse pending orders"
	// VirtueMart replaces a pending order with a new one that keeps the number.
	private static function attemptsTable(): string {
		static $name;

		if (!isset($name)) {
			$db = JFactory::getDbo();
			$db->setQuery('CREATE TABLE IF NOT EXISTS '.$db->quoteName($name = '#__virtuemart_payment_plg_payneteasy_attempts').' ('
				.'`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `order_number` VARCHAR(64) NOT NULL, `virtuemart_order_id` INT UNSIGNED NOT NULL,'
				.' `payneteasy_order_id` VARCHAR(48) NOT NULL, `created_on` DATETIME NOT NULL,'
				.' PRIMARY KEY (`id`), KEY `order_number` (`order_number`), KEY `virtuemart_order_id` (`virtuemart_order_id`)) DEFAULT CHARSET=utf8mb4')->execute();
		}

		return $name;
	}

	private static function addAttempt(stdClass $Details, string $paynet_id): void {
		[ $db, $table ] = [ JFactory::getDbo(), self::attemptsTable() ];

		if ($paynet_id === '' || $db->setQuery('SELECT COUNT(*) FROM '.$db->quoteName($table)
				.' WHERE `order_number`='.$db->quote($Details->order_number).' AND `payneteasy_order_id`='.$db->quote($paynet_id))->loadResult())
			return;

		$db->setQuery('INSERT INTO '.$db->quoteName($table).' (`order_number`, `virtuemart_order_id`, `payneteasy_order_id`, `created_on`) VALUES ('
			.join(',', [ $db->quote($Details->order_number), (int)$Details->virtuemart_order_id, $db->quote($paynet_id), $db->quote(JFactory::getDate()->toSql()) ]).')')->execute();
	}

	// Gateway ids of all payment attempts of the order, newest first
	private function attemptIds(stdClass $BT): array {
		$db = JFactory::getDbo();
		$ids = $db->setQuery('SELECT `payneteasy_order_id` FROM '.$db->quoteName(self::attemptsTable())
			.' WHERE `order_number`='.$db->quote($BT->order_number).' ORDER BY `id` DESC')->loadColumn() ?: [];

		// attempts stored before the attempts table existed
		foreach (array_reverse($this->getDatasByOrderId($BT->virtuemart_order_id) ?: []) as $Row)
			$ids[] = (string)$Row->payneteasy_order_id;

		return array_values(array_unique(array_filter($ids)));
	}

	// The order of the request; a pending order replaced by VirtueMart leads to its successor with the same number
	private function currentOrder(int $orderId): ?array {
		if ($orderId <= 0)
			return null;

		if (($aOrder = VmModel::getModel('orders')->getOrder($orderId)) && !empty($aOrder['details']['BT']))
			return $aOrder;

		$db = JFactory::getDbo();
		$number = $db->setQuery('SELECT `order_number` FROM '.$db->quoteName(self::attemptsTable())
			." WHERE `virtuemart_order_id`=$orderId ORDER BY `id` DESC", 0, 1)->loadResult();

		if ($number && ($id = (int)VirtueMartModelOrders::getOrderIdByOrderNumber($number))
				&& ($aOrder = VmModel::getModel('orders')->getOrder($id)) && !empty($aOrder['details']['BT']))
			return $aOrder;

		return null;
	}

	// Order-specific content is shown only to the customer of the order:
	// the key of the return address, the logged-in user or the cart of the session.
	private static function isCustomer(stdClass $BT, int $reqOrderId): bool {
		if (hash_equals(self::returnKey($reqOrderId, (string)$BT->order_number), self::reqStr('key')))
			return true;

		if (($User = JFactory::getApplication()->getIdentity()) && (int)$User->id && (int)$User->id === (int)$BT->virtuemart_user_id)
			return true;

		return self::isCartOf($BT);
	}

	private static function isCartOf(stdClass $BT): bool {
		$Cart = VirtueMartCart::getCart();

		return (int)$Cart->virtuemart_order_id === (int)$BT->virtuemart_order_id
			|| ((string)$Cart->order_number !== '' && (string)$Cart->order_number === (string)$BT->order_number);
	}

	private static function isPaidInFull(stdClass $BT, array $aResult): bool {
		return is_numeric($amount = trim((string)($aResult['amount'] ?? '')))
			&& strtoupper(trim((string)($aResult['currency'] ?? ''))) === strtoupper((string)ShopFunctions::getCurrencyByID($BT->order_currency, 'currency_code_3'))
			&& (int)round((float)$amount * 100) === (int)round((float)$BT->order_total * 100);
	}

	private function saleData(VirtueMartCart $Cart, stdClass $Details): array {
		$ret_url = $this->returnUrl($Details);

		$data = [
			'client_orderid' => $Details->order_number,
			'order_desc' => sprintf(self::_T('ORDER_DESC'), VmModel::getModel('Vendor')->getVendor($Details->virtuemart_vendor_id)->vendor_store_name, $Details->order_number),
			'amount' => $Details->order_total,
			'currency' => ShopFunctions::getCurrencyByID($Details->order_currency, 'currency_code_3'),
			'address1' => $Details->address_1,
			'city' => $Details->city,
			'zip_code' => $Details->zip,
			'country' => ShopFunctions::getCountryByID($Details->virtuemart_country_id, 'country_2_code'),
			'state' => isset($Details->virtuemart_state_id) ? ShopFunctions::getStateByID($Details->virtuemart_state_id, 'state_2_code') : '',
			'phone' => $Details->phone_1 ?: '',
			'email' => $Details->email,
			'ipaddress' => $Details->ip_address,
			'first_name' => $Details->first_name,
			'last_name' => $Details->last_name,
			'redirect_success_url' => $ret_url,
			'redirect_fail_url' => $ret_url,
			'redirect_url' => $ret_url,
			'server_callback_url' => $ret_url ];

		return $data;
	}

	private function cardData(): array {
		return [
			'credit_card_number' => preg_replace('/\D+/', '', vRequest::getString('credit_card_number')),
			'card_printed_name'  => trim(vRequest::getString('card_printed_name')),
			'expire_month'       => sprintf('%02d', vRequest::getString('expire_month')),
			'expire_year'        => vRequest::getString('expire_year'),
			'cvv2'               => vRequest::getString('cvv2') ];
	}

	private function Details($aOrder, bool $withPayneteasy=false): stdClass  {
		if (isset($aOrder['details']['ST']))
			$Details = $aOrder['details']['ST'];
		elseif (isset($aOrder['details']['BT']))
			$Details = $aOrder['details']['BT'];
		else
			throw new \Exception('Details are not found in the aOrder');

		if ($withPayneteasy) {
			$data = $this->getDataByOrderId($Details->virtuemart_order_id);
			foreach (explode(' ', 'payment_name payneteasy_order_id payneteasy_status payneteasy_descriptor') as $field)
				$Details->$field = $data->$field;
		}

		return $Details;
	}

	private function updateDetails(stdClass $Details, array $aResult) {
		self::addAttempt($Details, (string)($aResult['paynet-order-id'] ?? $aResult['orderid'] ?? ''));

		$this->storePSPluginInternalData([
			'virtuemart_order_id'         => $Details->virtuemart_order_id,
			'virtuemart_paymentmethod_id' => $Details->virtuemart_paymentmethod_id,
			'payment_name'                => self::_T('PAYMENT_NAME'),
			'payneteasy_order_id'         => $aResult['paynet-order-id'] ?? $aResult['orderid'] ?? '',
			'payneteasy_status'           => "{$aResult['type']} ".($aResult['status'] ?? ''),
			'payneteasy_descriptor'       => $aResult['descriptor'] ?? '' ]);
	}

	private function aOrder($orderId): array {
		if (!($aOrder = VmModel::getModel('orders')->getOrder($orderId)))
			throw new \Exception("Invalid order id $orderId");

		return $aOrder;
	}

	private function updateOrder_html(stdClass $Details, TablePaymentmethods $Method, string $status, bool $is_error=false): string {
		[ $upd['virtuemart_order_id'], $upd['order_status'], $upd['customer_notified'], $upd['comments'] ]
			= [ $Details->virtuemart_order_id, $status, (int)($status == 'F'), self::_T('PAYMENT_' .['D'=>'DECLINED','F'=>'APPROVED','E'=>'ERROR'][$is_error ? 'E' : $status]) ];

		VmModel::getModel('orders')->updateStatusForOneOrder($Details->virtuemart_order_id, $upd);

		return $upd['comments'];
	}



	public function plgVmConfirmedOrder(VirtueMartCart $Cart, array $aOrder) {
		if (!($Method = $this->getVmPluginMethod($Cart->virtuemart_paymentmethod_id)))
			return null;

		if (!$this->selectedThisElement($Method->payment_element))
			return false;

		$Details = $this->Details($aOrder);

		try
			{ $aResult = self::Api($Method)->sale(array_merge($this->saleData($Cart, $Details), $Method->IS_FORM ? [] : $this->cardData())); }
		catch (PneUnknownResultException $e) {
			// No answer from the gateway: the sale may have gone through, the order stays pending
			VmModel::getModel('orders')->_updateOrderHist($Details->virtuemart_order_id, $Details->order_status ?? 'P', 0, self::_T('PAYMENT_UNKNOWN'));
			vRequest::setVar('html', self::_T('PAYMENT_UNKNOWN'));
			return null;
		}
		catch (Exception $e) {
			vRequest::setVar('html', $this->updateOrder_html($Details, $Method, 'D'));
			return null;
		}

		[ $Cart->_confirmDone, $Cart->_dataValidated ] = [ false, false ];

		$Cart->setCartIntoSession();
		$this->updateDetails($Details, $aResult);

		JFactory::getApplication()->redirect($aResult['redirect-url'] ?? $this->returnUrl($Details));
	}

	public function plgVmOnPaymentResponseReceived(string &$html, &$paymentResponse) {
		[ $reqOrderId, $methodId ] = [ self::reqInt('orderId'), self::reqInt('methodId') ];

		if (!($Method = $this->getVmPluginMethod($methodId)))
			return null;

		if (!$this->selectedThisElement($Method->payment_element))
			return false;

		// Anyone can open this address: unless the customer of the order asks, the answer says nothing about the order
		[ $thanks, $paymentResponse, $html ] = [ $paymentResponse, '', self::_T('PAYMENT_NEUTRAL') ];

		// Only orders paid with this method
		if (!($aOrder = $this->currentOrder($reqOrderId))
				|| (int)($BT = $aOrder['details']['BT'])->virtuemart_paymentmethod_id !== (int)$Method->virtuemart_paymentmethod_id)
			return;

		$isCustomer = self::isCustomer($BT, $reqOrderId);

		// Change the status only of an order waiting for payment
		if (!in_array($BT->order_status, [ 'P', 'U' ], true)) {
			if ($isCustomer && ($result = [ 'F'=>'APPROVED', 'C'=>'APPROVED', 'S'=>'APPROVED', 'D'=>'DECLINED' ][$BT->order_status] ?? null))
				[ $paymentResponse, $html ] = $this->resultPage($BT, self::_T("PAYMENT_$result"), 'APPROVED' == $result ? $thanks : '');

			return;
		}

		// All attempts of the order are checked, newest first: an approved one wins.
		// The gateway is always asked, nothing is taken from the request.
		$ids = $this->attemptIds($BT);

		// The id given by the gateway callback (e.g. the answer to the sale request was lost) counts only if the gateway knows it for this order
		$reqId = preg_match('/^\d{1,24}$/', $reqId = self::reqStr('orderid')) && !in_array($reqId, $ids, true) ? $reqId : null;

		[ $approved, $mismatch, $last, $is_pending, $form ] = [ null, null, null, false, null ];

		foreach (array_merge($ids, (array)$reqId) as $i => $id) {
			try
				{ $aResult = self::Api($Method)->status([ 'client_orderid' => $BT->order_number, 'orderid' => $id ]); }
			catch (Exception $e)
				{ $aResult = []; }

			$status = trim((string)($aResult['status'] ?? ''));

			// The gateway answers for any id, also of another order, and with the status "error" for an id it does not know
			$is_ours = ($aResult['merchant-order-id'] ?? '') === (string)$BT->order_number && ($aResult['paynet-order-id'] ?? '') === $id;

			if ($id === $reqId) {
				if (!$is_ours || !isset($aResult['transaction-type']))
					continue;

				self::addAttempt($BT, $id);
			}
			elseif (!$is_ours)
				$status = '';

			if ('approved' == $status && self::isPaidInFull($BT, $aResult)) {
				$approved = $aResult;
				break;
			}

			if ('approved' == $status)
				$mismatch ??= $aResult;
			elseif (in_array($status, [ 'declined', 'filtered', 'error' ], true))
				$last ??= $aResult;
			else {
				// "processing", an unknown status or a failed status request (no status): the result is not known yet
				$is_pending = true;

				if (0 === $i && isset($aResult['html']))
					$form = $aResult['html'];
			}
		}

		if ($approved) {
			$this->updateDetails($BT, $approved);
			$text = $this->updateOrder_html($BT, $Method, 'F');

			if ($isCustomer)
				[ $paymentResponse, $html ] = $this->resultPage($BT, $text, $thanks);

			return;
		}

		// Approved, but not for the amount and currency of the order: the order is not confirmed, the merchant is told once
		if ($mismatch) {
			if ('amount mismatch' != ($this->getDataByOrderId($BT->virtuemart_order_id)->payneteasy_status ?? '')) {
				$this->updateDetails($BT, array_merge($mismatch, [ 'type' => 'amount', 'status' => 'mismatch' ]));
				VmModel::getModel('orders')->_updateOrderHist($BT->virtuemart_order_id, $BT->order_status, 0, sprintf(self::_T('PAYMENT_MISMATCH'),
					trim(($mismatch['amount'] ?? '').' '.($mismatch['currency'] ?? '')),
					round((float)$BT->order_total, 2).' '.ShopFunctions::getCurrencyByID($BT->order_currency, 'currency_code_3')));
			}

			if ($isCustomer)
				$html = self::_T('PAYMENT_CHECK');

			return;
		}

		// Only a final gateway status changes the order: while the result of any attempt is not known, it waits for payment
		if ($is_pending) {
			if ($isCustomer && isset($form)) {
				echo $form;
				JFactory::getApplication()->close();
			}

			if ($isCustomer)
				$html = self::tickerHtml($reqOrderId, $methodId, self::returnKey($reqOrderId, (string)$BT->order_number));

			return;
		}

		if (!$last)
			return;

		$this->updateDetails($BT, $last);
		$text = $this->updateOrder_html($BT, $Method, 'D', 'error' == $last['status']);

		if ($isCustomer)
			[ $paymentResponse, $html ] = $this->resultPage($BT, $text, '');
	}

	// The result page of the customer: [ paymentResponse, html ]
	private function resultPage(stdClass $BT, string $text, string $thanks): array {
		if ($thanks !== '' && self::isCartOf($BT))
			VirtueMartCart::getCart()->emptyCart();

		return [ $thanks, $text.self::clearBrowserCartForm() ];
	}

	private static function tickerHtml(int $orderId, int $methodId, string $key): string {
		return sprintf(<<<HTML
				<div style="width:100%%;text-align:center"><h1>%s</h1><a href="%s" id="pne_ticker">%s</a></div>
				<script>(()=>{let t=document.getElementById("pne_ticker"),s=t.innerHTML,p=0
				,iv=setInterval(()=>{if(++p<=s.length){if(s[p]==" ")p++
				;t.innerHTML="<span style='color:#09C'>"+s.slice(0,p)+"</span>"+s.slice(p)}
				else{clearInterval(iv);t.click()}},300)})()</script>
			HTML,
				self::_T('PAYMENT_PROCESSING'),
				JRoute::_(JUri::root()."index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&orderId=$orderId&methodId=$methodId&key=$key"),
				self::_T('CHECK_STATUS'));
	}

	private static function clearBrowserCartForm(): string
			{ return '<script>"credit_card_number card_printed_name expire_month expire_year".split(" ").forEach(k=>sessionStorage.removeItem("pne_"+k))</script>'; }

	public function plgVmOnUpdateOrderPayment(&$Order, $oldOrderStatus) {
		if (!($Method = $this->getVmPluginMethod($Order->virtuemart_paymentmethod_id)))
			return null;

		if (!$this->selectedThisElement($Method->payment_element))
			return false;
	
		return true;
	}

	public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id) {
		if (!$this->selectedThisByMethodId ($payment_method_id))
			return null;

		if ($Data = $this->getDataByOrderId($virtuemart_order_id))
			return '<table class="adminlist table">'
				.$this->getHtmlHeaderBE()
				.$this->getHtmlRowBE('PAYNETEASY_ORDER_ID', $Data->payneteasy_order_id)
				.($Data->payneteasy_descriptor != '' ? $this->getHtmlRowBE('PAYNETEASY_DESCRIPTOR', $Data->payneteasy_descriptor) : '')
				.'</table>';

		return '';
	}

	public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
		if (!($Method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
			return null;

		if (!$this->selectedThisElement($Method->payment_element))
			return false;

		$this->getPaymentCurrency($Method);
		$paymentCurrencyId = $Method->payment_currency;
	}

	public function plgVmDisplayListFEPayment(VirtueMartCart $Cart, $selected, &$htmlIn) {
		if ($this->getPluginMethods($Cart->vendorId) == 0)
			return false;

		[ $idN, $ret ] = [ "virtuemart_{$this->_psType}method_id", false ];

		foreach ($this->methods as $Method) {
			if (isset($htmlIn[$this->_psType][$Method->$idN])) {
				$ret = true;
				continue;
			}

			if (!$this->checkConditions($Cart, $Method, $Cart->cartPrices))
				continue;

			$prices = $Cart->cartPrices;
			$salesPrice = $this->setCartPrices($Cart, $prices, $Method);
			$Method->{"{$this->_psType}_name"} = $this->renderPluginName($Method);

			$html = $this->getPluginHtml($Method, $selected, $salesPrice);

			if (!$Method->IS_FORM)
				$html .= $this->renderByLayout('cardform', [ 'Method' => $Method ]);

			$htmlIn[$this->_psType][$Method->$idN] = $html;
			$ret = true;
		}

		return $ret;
	}

	public function plgVmDeclarePluginParamsPaymentVM3(&$data)
		{ return $this->declarePluginParams('payment', $data); } 

	public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
		{ return $this->setOnTablePluginParams($name, $id, $table); }

	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
		{ $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name); }

	public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
		{ return $this->onStoreInstallPluginTable($jplugin_id); }

	/*
	public function getVmPluginCreateTableSQL() { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function setPluginConfig($pluginConfig) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	protected function getPluginConfig($key) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function plgVmOnUserPaymentCancel() { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function plgVmOnPaymentNotification() { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $Cart, array $cart_prices, &$paymentCounter) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function plgVmOnShowOrderPrintPayment($order_number, $method_id) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function paymentrefundNotification() { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	protected function updateDeleteOldOrderStatus($orderId, $historyStatus) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }
	*/
}
