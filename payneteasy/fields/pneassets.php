<?php

defined('JPATH_BASE') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

class JFormFieldPneassets extends JFormField {
	protected function getInput() {
		$D = Factory::getApplication()->getDocument();

		$D->addScript(Uri::root(true).'/plugins/vmpayment/payneteasy/assets/js/admin_settings.js', [], ['defer' => true]);
		$D->addScriptDeclaration('window.pneAdminSettings'
			.' = {"field_prefix":"params_","row_selector":".control-group","submitter":Joomla.submitbutton,"submit_hook":(hook) => { Joomla.submitbutton = hook }}');

		$D->getWebAssetManager()
			->addInlineStyle('.control-group:has(> .control-input > template){display:none}'
				.'input[type=text]{max-width:400px !important}'
				.'.control-input label{font-size:small}'
				.'.form-check-inline{vertical-align:middle}');

		return '<template></template>';
	}
}
