(function () {
	'use strict';

	function getConfig() {
		if (typeof deoiaSubscriptions === 'undefined') {
			return null;
		}
		return deoiaSubscriptions;
	}

	function showError(errorsEl, message) {
		if (!errorsEl) {
			return;
		}
		errorsEl.textContent = message || '';
		errorsEl.hidden = !message;
	}

	function normalizeSlug(input) {
		if (input === null || typeof input === 'undefined') {
			return '';
		}

		var s = String(input).trim();
		if (!s) {
			return '';
		}

		s = s.toLowerCase();
		s = s.replace(/\u00f1/g, 'n');
		s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		s = s.replace(/[^a-z0-9]+/g, '-');
		s = s.replace(/-+/g, '-');
		s = s.replace(/^-+|-+$/g, '');

		return s;
	}

	function availabilityMessage(reason) {
		var messages = {
			empty: 'Escribe un nombre para sugerir tu subdominio.',
			too_short: 'El subdominio debe tener al menos 3 caracteres.',
			too_long: 'El subdominio debe tener máximo 40 caracteres.',
			invalid_format: 'Usa solo letras, números y guiones, sin guiones al inicio o al final.',
			reserved: 'Ese subdominio está reservado. Elige otro.',
			taken: 'Ese subdominio ya está tomado. Cambia el nombre o elige una variante.'
		};

		return messages[reason] || 'No pudimos comprobar el subdominio ahora. Intenta de nuevo.';
	}

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('deoia-subscription-form');
		if (!form) {
			return;
		}

		var cfg = getConfig();
		if (!cfg || !cfg.restUrl) {
			showError(document.getElementById('deoia-subscription-errors'), 'No se pudo cargar la configuración del formulario.');
			return;
		}

		var submitBtn = document.getElementById('deoia-subscription-submit');
		var plansEl = document.getElementById('deoia-subscription-plans');
		var freemiumCta = document.getElementById('deoia-plan-freemium-cta');
		var proCta = document.getElementById('deoia-plan-pro-cta');
		var plansBackBtn = document.getElementById('deoia-plans-back');
		var errorsEl = document.getElementById('deoia-subscription-errors');
		var agendaInput = document.getElementById('deoia-agenda-name');
		var slugStatus = document.getElementById('deoia-slug-status');
		var slugFieldWrap = document.getElementById('deoia-slug-field-wrap');
		var slugInput = document.getElementById('deoia-desired-slug');
		var hiddenSlugInput = document.getElementById('deoia-desired-slug-hidden');
		var suggestionsEl = document.getElementById('deoia-slug-suggestions');
		var publicDomain = cfg.publicDomain || 'deoia.com';
		var debounceTimer = null;
		var requestSeq = 0;
		var activeController = null;
		var manualMode = false;
		var lastSlug = '';
		var lastAvailabilityState = 'idle';
		var lastAvailabilityReason = null;
		var isChecking = false;

		function setSlugStatus(message, state) {
			if (!slugStatus) {
				return;
			}

			slugStatus.textContent = message || '';
			slugStatus.hidden = !message;
			slugStatus.setAttribute('data-state', state || 'idle');
		}

		function rememberAvailability(slug, state, reason) {
			lastSlug = slug || '';
			lastAvailabilityState = state || 'idle';
			lastAvailabilityReason = reason || null;
			isChecking = state === 'checking';
		}

		function setSlugFieldVisible(visible) {
			if (slugFieldWrap) {
				slugFieldWrap.hidden = !visible;
			}
		}

		function clearSuggestions() {
			if (!suggestionsEl) {
				return;
			}

			suggestionsEl.textContent = '';
			suggestionsEl.hidden = true;
		}

		function renderSuggestions(suggestions) {
			clearSuggestions();
			if (!suggestionsEl || !Array.isArray(suggestions) || suggestions.length === 0) {
				return;
			}

			suggestionsEl.hidden = false;
			suggestions.forEach(function (suggestion) {
				var button = document.createElement('button');
				button.type = 'button';
				button.textContent = 'Usar ' + suggestion;
				button.addEventListener('click', function () {
					manualMode = true;
					if (slugInput) {
						slugInput.value = suggestion;
					}
					if (hiddenSlugInput) {
						hiddenSlugInput.value = suggestion;
					}
					setSlugFieldVisible(true);
					scheduleAvailabilityCheck(suggestion, true);
				});
				suggestionsEl.appendChild(button);
				suggestionsEl.appendChild(document.createTextNode(' '));
			});
		}

		function updateHiddenSlug(slug) {
			if (hiddenSlugInput) {
				hiddenSlugInput.value = slug || '';
			}
		}

		function shouldShowManualField(result) {
			if (manualMode) {
				return true;
			}
			return result && result.available === false && result.reason !== 'empty';
		}

		function currentSlugCandidate() {
			if (manualMode && slugInput) {
				return normalizeSlug(slugInput.value);
			}
			return normalizeSlug(agendaInput ? agendaInput.value : '');
		}

		function scheduleAvailabilityCheck(rawValue, forceShowField) {
			var slug = normalizeSlug(rawValue);
			requestSeq++;
			updateHiddenSlug(slug);
			clearSuggestions();

			if (debounceTimer) {
				window.clearTimeout(debounceTimer);
			}

			if (!slug) {
				if (activeController) {
					activeController.abort();
					activeController = null;
				}
				rememberAvailability('', 'idle', 'empty');
				setSlugStatus('Escribe un nombre para sugerir tu subdominio.', 'idle');
				setSlugFieldVisible(Boolean(forceShowField && manualMode));
				return;
			}

			rememberAvailability(slug, 'checking', null);
			setSlugStatus('Comprobando disponibilidad...', 'checking');
			setSlugFieldVisible(Boolean(forceShowField || manualMode));

			debounceTimer = window.setTimeout(function () {
				checkAvailability(slug);
			}, 500);
		}

		function checkAvailability(slug) {
			if (!cfg.slugAvailabilityUrl) {
				rememberAvailability(slug, 'error', null);
				setSlugStatus('No pudimos comprobar el subdominio ahora. Intenta de nuevo.', 'error');
				setSlugFieldVisible(manualMode);
				return;
			}

			requestSeq++;
			var seq = requestSeq;

			if (activeController) {
				activeController.abort();
			}

			if (typeof AbortController !== 'undefined') {
				activeController = new AbortController();
			} else {
				activeController = null;
			}

			var url = cfg.slugAvailabilityUrl + '?slug=' + encodeURIComponent(slug);
			var options = {
				method: 'GET',
				headers: {
					'X-WP-Nonce': cfg.nonce || ''
				},
				credentials: 'same-origin'
			};

			if (activeController) {
				options.signal = activeController.signal;
			}

			fetch(url, options)
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, status: res.status, data: data };
					});
				})
				.then(function (result) {
					if (seq !== requestSeq) {
						return;
					}

					if (!result.ok || !result.data) {
						rememberAvailability(slug, 'error', null);
						setSlugStatus('No pudimos comprobar el subdominio ahora. Intenta de nuevo.', 'error');
						setSlugFieldVisible(manualMode);
						clearSuggestions();
						return;
					}

					applyAvailabilityResult(result.data);
				})
				.catch(function (err) {
					if (seq !== requestSeq || (err && err.name === 'AbortError')) {
						return;
					}
					rememberAvailability(slug, 'error', null);
					setSlugStatus('No pudimos comprobar el subdominio ahora. Intenta de nuevo.', 'error');
					setSlugFieldVisible(manualMode);
					clearSuggestions();
				});
		}

		function applyAvailabilityResult(result) {
			var slug = result.canonical_slug || currentSlugCandidate();
			updateHiddenSlug(slug);

			if (manualMode && slugInput && slugInput.value !== slug) {
				slugInput.value = slug;
			}

			if (result.available === true) {
				rememberAvailability(slug, 'available', null);
				setSlugStatus('Tu subdominio será ' + slug + '.' + publicDomain, 'available');
				setSlugFieldVisible(manualMode);
				clearSuggestions();
				return;
			}

			rememberAvailability(slug, result.reason === 'taken' ? 'taken' : 'invalid', result.reason);
			setSlugStatus(availabilityMessage(result.reason), result.reason === 'taken' ? 'taken' : 'invalid');
			setSlugFieldVisible(shouldShowManualField(result));
			renderSuggestions(result.suggestions || []);
		}

		function validateSlugBeforeSubmit() {
			var desiredSlug = hiddenSlugInput ? hiddenSlugInput.value || '' : '';

			if (!desiredSlug) {
				return 'Elige un subdominio antes de continuar.';
			}

			if (isChecking || lastAvailabilityState === 'checking') {
				return 'Espera un momento: estamos comprobando tu subdominio.';
			}

			if (lastSlug !== desiredSlug) {
				scheduleAvailabilityCheck(desiredSlug, true);
				return 'Espera un momento: estamos comprobando tu subdominio.';
			}

			if (lastAvailabilityState === 'taken') {
				return 'Ese subdominio ya está tomado. Elige otro antes de continuar.';
			}

			if (lastAvailabilityState === 'invalid') {
				return availabilityMessage(lastAvailabilityReason);
			}

			if (lastAvailabilityState === 'error') {
				return 'No pudimos confirmar el subdominio. Intenta de nuevo.';
			}

			if (lastAvailabilityState !== 'available') {
				scheduleAvailabilityCheck(desiredSlug, true);
				return 'Espera un momento: estamos comprobando tu subdominio.';
			}

			return '';
		}

		function handleSlugStartError(data, status) {
			if (!data || (data.error !== 'slug_unavailable' && data.error !== 'invalid_slug')) {
				return false;
			}

			var slug = data.canonical_slug || (hiddenSlugInput ? hiddenSlugInput.value : '');
			manualMode = true;
			setSlugFieldVisible(true);
			updateHiddenSlug(slug);
			if (slugInput) {
				slugInput.value = slug;
				slugInput.focus();
			}

			if (data.error === 'slug_unavailable' || status === 409) {
				rememberAvailability(slug, 'taken', 'taken');
				setSlugStatus('Ese subdominio ya está tomado. Elige otro antes de continuar.', 'taken');
				renderSuggestions(data.suggestions || []);
				showError(errorsEl, 'Ese subdominio ya está tomado. Elige otro antes de continuar.');
				return true;
			}

			rememberAvailability(slug, 'invalid', data.reason);
			setSlugStatus(availabilityMessage(data.reason), 'invalid');
			clearSuggestions();
			showError(errorsEl, availabilityMessage(data.reason));
			return true;
		}

		if (agendaInput) {
			agendaInput.addEventListener('input', function () {
				if (!manualMode && slugInput) {
					slugInput.value = normalizeSlug(agendaInput.value);
				}
				scheduleAvailabilityCheck(manualMode && slugInput ? slugInput.value : agendaInput.value, false);
			});
		}

		if (slugInput) {
			slugInput.addEventListener('input', function () {
				var raw = slugInput.value || '';
				if (raw.trim() === '') {
					manualMode = false;
					setSlugFieldVisible(false);
					scheduleAvailabilityCheck(agendaInput ? agendaInput.value : '', false);
					return;
				}

				manualMode = true;
				scheduleAvailabilityCheck(raw, true);
			});
		}

		function getFormPayload() {
			var agendaName = (form.querySelector('[name="agenda_name"]') || {}).value || '';
			var email = (form.querySelector('[name="email"]') || {}).value || '';
			var ownerName = (form.querySelector('[name="owner_name"]') || {}).value || '';
			var desiredSlug = (hiddenSlugInput || {}).value || '';

			return {
				agenda_name: agendaName.trim(),
				email: email.trim(),
				owner_name: ownerName.trim(),
				desired_slug: desiredSlug.trim()
			};
		}

		function getFreemiumPayload() {
			var payload = getFormPayload();
			var website = (form.querySelector('[name="website"]') || {}).value || '';
			payload.website = website.trim();
			return payload;
		}

		function setPlanSelectionVisible(visible) {
			form.classList.toggle('is-selecting-plan', Boolean(visible));
			if (plansEl) {
				plansEl.hidden = !visible;
			}
			if (submitBtn) {
				submitBtn.hidden = Boolean(visible);
			}
		}

		function setPlanButtonsDisabled(disabled) {
			if (freemiumCta) {
				freemiumCta.disabled = disabled;
			}
			if (proCta) {
				proCta.disabled = disabled;
			}
			if (plansBackBtn) {
				plansBackBtn.disabled = disabled;
			}
		}

		// Shared request runner for both plan flows. `onSuccess` decides where to
		// redirect based on the backend response (checkout_url for PRO,
		// redirect_url for Freemium).
		function startPlan(url, onSuccess, getPayload) {
			showError(errorsEl, '');
			setPlanButtonsDisabled(true);

			var payload = typeof getPayload === 'function' ? getPayload() : getFormPayload();

			fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce || ''
				},
				body: JSON.stringify(payload),
				credentials: 'same-origin'
			})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, status: res.status, data: data };
					});
				})
				.then(function (result) {
					if (result.ok && onSuccess(result.data)) {
						return;
					}
					if (handleSlugStartError(result.data, result.status)) {
						setPlanSelectionVisible(false);
						return;
					}
					var msg =
						(result.data && result.data.error) ||
						(result.data && result.data.message) ||
						'No se pudo continuar. Intenta de nuevo.';
					showError(errorsEl, typeof msg === 'string' ? msg : 'Error desconocido.');
				})
				.catch(function () {
					showError(errorsEl, 'Error de red. Comprueba tu conexión.');
				})
				.finally(function () {
					setPlanButtonsDisabled(false);
				});
		}

		// Step 1: validate the form, then reveal the inline plan selection.
		// No network call and no Stripe redirect happens here.
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			showError(errorsEl, '');

			var slugSubmitError = validateSlugBeforeSubmit();

			if (slugSubmitError) {
				showError(errorsEl, slugSubmitError);
				if (slugInput && (lastAvailabilityState === 'taken' || lastAvailabilityState === 'invalid' || lastAvailabilityState === 'error')) {
					manualMode = true;
					setSlugFieldVisible(true);
					slugInput.focus();
				}
				return;
			}

			setPlanSelectionVisible(true);
		});

		if (plansBackBtn) {
			plansBackBtn.addEventListener('click', function () {
				showError(errorsEl, '');
				setPlanSelectionVisible(false);
			});
		}

		// PRO: unchanged contract — expects checkout_url and redirects to Stripe.
		if (proCta) {
			proCta.addEventListener('click', function () {
				startPlan(cfg.restUrl, function (data) {
					if (data && data.checkout_url) {
						window.location.href = data.checkout_url;
						return true;
					}
					return false;
				});
			});
		}

		// Freemium: new flow — provisions without Stripe and redirects to the
		// thank-you page once the request is accepted.
		if (freemiumCta) {
			freemiumCta.addEventListener('click', function () {
				if (!cfg.freemiumUrl) {
					showError(errorsEl, 'El plan Freemium no está disponible ahora.');
					return;
				}
				startPlan(cfg.freemiumUrl, function (data) {
					if (data && (data.status === 'provisioning_started' || data.ok)) {
						var target = data.redirect_url || cfg.freemiumRedirectUrl;
						if (target) {
							window.location.href = target;
							return true;
						}
					}
					return false;
				}, getFreemiumPayload);
			});
		}
	});
})();
