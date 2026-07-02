(function (global) {
	'use strict';

	var COMMON_PROVIDERS = [
		'gmail.com',
		'googlemail.com',
		'hotmail.com',
		'outlook.com',
		'live.com',
		'hotmail.com.mx',
		'outlook.es',
		'yahoo.com',
		'yahoo.com.mx',
		'icloud.com',
		'me.com'
	];

	var TYPO_BLOCKLIST = {
		'gmqil.com': 'gmail.com',
		'gamil.com': 'gmail.com',
		'gnail.com': 'gmail.com',
		'gmial.com': 'gmail.com',
		'gmai.com': 'gmail.com',
		'hotmial.com': 'hotmail.com',
		'hotmal.com': 'hotmail.com',
		'outlok.com': 'outlook.com',
		'outlook.con': 'outlook.com',
		'yahoo.con': 'yahoo.com',
		'iclud.com': 'icloud.com'
	};

	var MAX_LEVENSHTEIN_DISTANCE = 1;
	var MAX_LENGTH_DELTA = 1;

	function levenshteinDistance(left, right) {
		var rows = left.length + 1;
		var cols = right.length + 1;
		var matrix = [];
		var i;
		var j;

		for (i = 0; i < rows; i += 1) {
			matrix[i] = new Array(cols);
			matrix[i][0] = i;
		}
		for (j = 0; j < cols; j += 1) {
			matrix[0][j] = j;
		}

		for (i = 1; i < rows; i += 1) {
			for (j = 1; j < cols; j += 1) {
				var cost = left.charAt(i - 1) === right.charAt(j - 1) ? 0 : 1;
				matrix[i][j] = Math.min(
					matrix[i - 1][j] + 1,
					matrix[i][j - 1] + 1,
					matrix[i - 1][j - 1] + cost
				);
			}
		}

		return matrix[left.length][right.length];
	}

	function parseSubscriptionEmail(raw) {
		if (raw === null || typeof raw === 'undefined') {
			return null;
		}

		var email = String(raw).trim().toLowerCase();
		var at = email.lastIndexOf('@');
		if (at <= 0 || at >= email.length - 1) {
			return null;
		}

		var local = email.slice(0, at);
		var domain = email.slice(at + 1);
		if (!local || !domain || domain.indexOf('.') === -1) {
			return null;
		}

		return { email: email, local: local, domain: domain };
	}

	function buildTypoFailure(local, domain, suggestedDomain) {
		return {
			ok: false,
			error: 'email_typo_suspected',
			detected_domain: domain,
			suggested_domain: suggestedDomain,
			suggested_email: local + '@' + suggestedDomain
		};
	}

	function validateSubscriptionEmail(raw) {
		var parsed = parseSubscriptionEmail(raw);
		if (!parsed) {
			return { ok: true };
		}

		var local = parsed.local;
		var domain = parsed.domain;
		var i;

		if (COMMON_PROVIDERS.indexOf(domain) !== -1) {
			return { ok: true };
		}

		if (Object.prototype.hasOwnProperty.call(TYPO_BLOCKLIST, domain)) {
			return buildTypoFailure(local, domain, TYPO_BLOCKLIST[domain]);
		}

		for (i = 0; i < COMMON_PROVIDERS.length; i += 1) {
			var provider = COMMON_PROVIDERS[i];
			var distance = levenshteinDistance(domain, provider);
			if (
				distance <= MAX_LEVENSHTEIN_DISTANCE &&
				Math.abs(domain.length - provider.length) <= MAX_LENGTH_DELTA
			) {
				return buildTypoFailure(local, domain, provider);
			}
		}

		return { ok: true };
	}

	function buildUserMessage(result) {
		if (result && result.suggested_domain) {
			return (
				'Revisa tu correo electrónico. Parece que escribiste un dominio incorrecto. ' +
				'¿Quisiste decir @' +
				result.suggested_domain +
				'? Corrige tu correo para continuar.'
			);
		}

		return 'Revisa tu correo electrónico. El dominio parece un error de escritura. Verifica que sea el correo correcto antes de continuar.';
	}

	global.DeoiaEmailTypoGuard = {
		validateSubscriptionEmail: validateSubscriptionEmail,
		buildUserMessage: buildUserMessage
	};
})(window);
