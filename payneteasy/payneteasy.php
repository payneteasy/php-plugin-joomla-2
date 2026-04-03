<?php

defined('_JEXEC') or die;

define('_PAYNETEASY_LIB_', true);
include_once('lib' .DIRECTORY_SEPARATOR .'Api.php');
use Payneteasy\lib as Payneteasy;

require_once(VMPATH_PLUGINLIBS .'/vmpsplugin.php');

class plgVmPaymentPayneteasy extends vmPSPlugin {
	private static $Api;

	public function __construct(&$subject, $config) {
		parent::__construct($subject, $config);

		[ $this->_tablepkey, $this->_tableId, $this->tableFields ] = [ 'id', 'id', array_keys($this->getTableSQLFields()) ];

		$varsToPush = $this->getVarsToPush();
		$this->addVarsToPushCore($varsToPush, 1);
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	private static function _T(string $arg): string
		{ return JText::_('PAYNETEASY_'.strtoupper($arg)); }

	public function getTableSQLFields() {
		return [
			'id'                          => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'INT UNSIGNED',
			'virtuemart_paymentmethod_id' => 'INT UNSIGNED',
			'payment_name'                => 'VARCHAR(16)',
			'payneteasy_order_id'         => 'VARCHAR(48)',
			'payneteasy_status'           => 'VARCHAR(48)',
			'payneteasy_descriptor'       => 'VARCHAR(128)' ];
	}

	public function plgVmConfirmedOrder(VirtueMartCart $Cart, array $aOrder) {
		if (!($Method = $this->getVmPluginMethod($Cart->virtuemart_paymentmethod_id)))
			return null;

		if (!$this->selectedThisElement($Method->payment_element))
			return false;

		$Details = $this->Details($aOrder);

		try
			{ $aResult = self::Api($Method)->sale( $this->saleData($Cart, $Details) ); }
		catch (Exception $e) {
			vRequest::setVar('html', $this->updateOrder_html($Details, $Method, 'X'));
			return null;
		}

		[ $Cart->_confirmDone, $Cart->_dataValidated ] = [ false, false ];
		$Cart->setCartIntoSession();

		$this->updateDetails($Details, $aResult);

		JFactory::getApplication()->redirect($aResult['redirect-url']);
	}

	public function plgVmOnPaymentResponseReceived(string &$html) {
		if (!($Method = $this->getVmPluginMethod( vRequest::getInt('methodId') )))
			return null;

		if (!$this->selectedThisElement($Method->payment_element))
			return false;
	
		$aResult = vRequest::getPost();
		if ($Method->log_trace)
			Payneteasy\trace($aResult);

		$aOrder = $this->aOrder( vRequest::getInt('orderId') );
		$Details = $this->Details($aOrder, true);
		$this->updateDetails($Details, $aResult);

		$html = $this->updateOrder_html($Details, $Method, ['approved'=>'F','declined'=>'D','filtered'=>'D'][$aResult['status']] ?? 'X');

		VirtueMartCart::getCart()->emptyCart();
	}

	public function plgVmOnUpdateOrderPayment(&$Order, $oldOrderStatus) {
		if (!($Method = $this->getVmPluginMethod($Order->virtuemart_paymentmethod_id)))
			return null;

		if (!$this->selectedThisElement($Method->payment_element))
			return false;
	
		return true;
	}



	private static function Api($Method)
		{ return self::$Api ??= new Payneteasy\Api($Method->is_sandbox ? $Method->url_sandbox : $Method->url_live, $Method->login, $Method->control_key, $Method->end_point, false, $Method->log_trace); }

	private function saleData(VirtueMartCart $Cart, stdClass $Details): array {
		$ret_url = JRoute::_(JURI::root() .'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived'
			.'&orderId=' .$Details->virtuemart_order_id
			.'&methodId=' .$Details->virtuemart_paymentmethod_id);

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

	private function updateOrder_html(stdClass $Details, TablePaymentmethods $Method, string $status): string {
		[ $upd['virtuemart_order_id'], $upd['order_status'], $upd['customer_notified'], $upd['comments'] ]
			= [ $Details->virtuemart_order_id, $status, (int)($status == 'F'), self::_T('PAYMENT_' .['D'=>'DECLINED','F'=>'APPROVED','X'=>'ERROR'][$status]) ];

		VmModel::getModel('orders')->updateStatusForOneOrder($Details->virtuemart_order_id, $upd);

		return $upd['comments'];
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

	public function plgVmDeclarePluginParamsPaymentVM3(&$data)
		{ return $this->declarePluginParams('payment', $data); } 

	public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
		{ return $this->setOnTablePluginParams($name, $id, $table); }

	public function plgVmDisplayListFEPayment(VirtueMartCart $Cart, $selected, &$htmlIn)
		{ return $this->displayListFE($Cart, $selected, $htmlIn); }

	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
		{ $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name); }



	/*
	public function getVmPluginCreateTableSQL() { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }
	
	public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function setPluginConfig($pluginConfig) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	protected function getPluginConfig($key) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function plgVmOnUserPaymentCancel() { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function plgVmOnPaymentNotification() { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $Cart, array $cart_prices, &$paymentCounter) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function plgVmOnShowOrderPrintPayment($order_number, $method_id) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	public function paymentrefundNotification() { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }

	protected function updateDeleteOldOrderStatus($orderId, $historyStatus) { error_log($msg=__CLASS__.'::'.__FUNCTION__);die($msg); }*/
}
