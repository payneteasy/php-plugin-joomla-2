($ => {
	'use strict'

	var fields = 'credit_card_number card_printed_name expire_month expire_year cvv2'.split(' ')
	var selector = fields.map(name => '[name="'+name+'"]').join(',')

	var checks = {
		credit_card_number: /^\d{12,19}$/,
		card_printed_name: /\S/,
		expire_month: /^(0?[1-9]|1[012])$/,
		expire_year: /^\d{4}$/,
		cvv2: /^\d{3,4}$/
	}

	function restore() {
		fields.forEach(name => {
			if (name == 'cvv2')
				return

			var el = document.querySelector('[name="'+name+'"]')
			var saved = sessionStorage.getItem('pne_'+name)
			if (el && saved && !el.value)
				el.value = saved
		})
	}

	function luhnValid(s) {
		var sum = 0, parity = s.length % 2
		for (var i = 0; i < s.length; i++) {
			var d = Number(s[i])
			if (i % 2 == parity)
				if ((d *= 2) > 9)
					d -= 9
			sum += d
		}
		return sum % 10 == 0
	}

	function expiryNotPast() {
		var m = Number($('[name="expire_month"]').val())
		var y = Number($('[name="expire_year"]').val())
		var now = new Date()
		return y > now.getFullYear() || (y == now.getFullYear() && m >= now.getMonth() + 1)
	}

	function validateField($f) {
		var name = $f.attr('name')
		var valid = checks[name].test($f.val())
			&& (name != 'credit_card_number' || luhnValid($f.val()))
			&& (name != 'expire_month' && name != 'expire_year' || expiryNotPast())
		$f.css('background-color', valid ? '' : '#FDD')
		return valid
	}

	$(document).on('input', selector, e => {
		var $f = $(e.target)
		validateField($f)
		if (e.target.name == 'expire_month')
			validateField($('[name="expire_year"]'))
		if (e.target.name == 'expire_year')
			validateField($('[name="expire_month"]'))
		if (e.target.name != 'cvv2')
			sessionStorage.setItem('pne_'+e.target.name, e.target.value)
	})

	document.addEventListener('click', e => {
		if (!e.target.closest('#checkoutFormSubmit'))
			return

		var $fields = $(selector)
		if (!$fields.length)
			return

		var $invalid = $($.grep($fields, f => validateField($(f)), true)).first()
		if ($invalid.length) {
			e.preventDefault()
			e.stopImmediatePropagation()
			$invalid.trigger('focus')
			$invalid.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' })
		}
	}, true)

	restore()

	new MutationObserver(restore).observe(document.documentElement, { childList: true, subtree: true })
})(jQuery)
