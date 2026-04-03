<?php

defined('JPATH_BASE') or die;

jimport('joomla.form.formfield');
class JFormFieldPayneteasyCss extends JFormField {
	protected function getInput() {
		vmJsApi::css('payneteasy', '/plugins/vmpayment/payneteasy/assets/css/');

		return '';
	}
}
