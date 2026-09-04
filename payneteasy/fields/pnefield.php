<?php
/**
 * Generic field wrapper: VirtueMart's own vmconfig form renderer only prints
 * $field->label and $field->input (see administrator/components/com_
 * virtuemart/fields/formrenderer.php) — the standard description attribute
 * is never shown. This delegates to the real field type named by
 * 'fieldtype' (text, password, radio, checkbox, ...) for the actual input,
 * then appends the description itself so it ends up inside $field->input,
 * which the renderer does print.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;

class JFormFieldPnefield extends JFormField {
	protected function getLabel() {
		if (in_array(($name = (string)$this->element['name']), ['SANDBOX_END_POINT', 'LIVE_END_POINT']))  {
			$this->element['label'] = sprintf(Text::_($orig = (string)$this->element['label']), $this->form->getValue('IS_MULTICURR') ? 'Endpoint Group ID' : 'Endpoint ID');
			[ $ret, $this->element['label'] ] = [ parent::getLabel(), $orig ];
			return $ret;
		}

		return parent::getLabel();
	}

	protected function getInput() {
		$this->element['size'] = 38;

		$field = FormHelper::loadFieldType($this->element['fieldtype'] ?? 'text');
		$field->setup($this->element, $this->value, $this->group);

		$cfg = $this->form->getData()->toArray()['params'];
		if (in_array(($name = (string)$this->element['name']), ['IS_LIVE','IS_MULTICURR']))
			[ $off_hidden, $on_hidden ] = $cfg[$name] ? [' style="display:none"',''] : ['',' style="display:none"'];

		$endpoint_label = $cfg['IS_MULTICURR'] ? 'Endpoint Group ID' : 'Endpoint ID';

		return sprintf('%s<div class="text-muted"><small>%s</small></div>',
			$field->type == 'Checkbox'
				? '<label>'.$field->getInput().Text::_($this->element['label'].'_LABEL').'</label>'
				: $field->getInput(),
			sprintf(Text::_($this->element['label'].'_DESC'), $off_hidden ?? '', $on_hidden ?? '', $endpoint_label ?? ''));
	}
}
