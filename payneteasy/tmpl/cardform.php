<?php
	defined('_JEXEC') or die;
	vmJsApi::addJScript('/plugins/vmpayment/payneteasy/assets/js/checkout.js', false, false, true);
	$css = fn($tail) => " style=\"display:block;height:auto;padding:.375rem .75rem;font-size:1rem;line-height:1.5;color:#212529;background-color:#fff;border:1px solid #dee2e6;border-radius:.375rem;width:$tail\"";
?>
<style>.vm-payment-plugin-single{flex-wrap:wrap}li:has(.pne-card-fields) .vmpayment_name{margin-left:25px;margin-top:-21px}</style>
<fieldset class="pne-card-fields" style="flex-basis:100%;width:100%;margin-top:1rem">
	<div style="display:flex;gap:1rem;flex-wrap:wrap">
		<p style="flex:0 0 200px"><label><?=JText::_('PAYNETEASY_CARD_NUMBER')?></label><br>
			<input name="credit_card_number" type="tel" autocomplete="cc-number" maxlength="20"<?=$css('100%')?>></p>
		<p style="flex:0 0 4em"><label><?=JText::_('PAYNETEASY_CARD_CVV')?></label><br>
			<input name="cvv2" type="password" autocomplete="cc-csc" maxlength="4"<?=$css('4em')?>></p>
	</div>
	<div style="display:flex;gap:1rem">
		<p><label><?=JText::_('PAYNETEASY_CARD_EXPIRY_MONTH')?></label><br>
			<input name="expire_month" type="tel" autocomplete="cc-exp-month" maxlength="2" placeholder="MM"<?=$css('4em')?>></p>
		<p><label><?=JText::_('PAYNETEASY_CARD_EXPIRY_YEAR')?></label><br>
			<input name="expire_year" type="tel" autocomplete="cc-exp-year" maxlength="4" placeholder="YYYY"<?=$css('6em')?>></p>
	</div>
	<p><label><?=JText::_('PAYNETEASY_CARD_NAME')?></label><br>
		<input name="card_printed_name" type="text" autocomplete="cc-name" maxlength="128"<?=$css('280px')?>></p>
</fieldset>
