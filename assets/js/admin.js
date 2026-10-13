(function () {
	'use strict';

	var roots = document.querySelectorAll('.cf7aiv-setup-page');

	Array.prototype.forEach.call(roots, function (root) {
		var steps = Array.prototype.slice.call(root.querySelectorAll('.cf7aiv-setup-step[data-step]'));
		var progressItems = Array.prototype.slice.call(root.querySelectorAll('[data-setup-step]'));
		var activeStep = null;

		if (!steps.length) {
			return;
		}

		var stepFor = function (name) {
			return steps.find(function (step) {
				return step.getAttribute('data-step') === name;
			}) || null;
		};

		var showAll = function () {
			root.classList.remove('is-progressive');
			steps.forEach(function (step) {
				step.classList.add('is-active');
				step.removeAttribute('aria-hidden');
				step.hidden = false;
			});
		};

		var setActive = function (step, shouldFocus) {
			if (!step) {
				return;
			}

			activeStep = step;
			steps.forEach(function (candidate) {
				var isActive = candidate === step;
				candidate.classList.toggle('is-active', isActive);
				candidate.setAttribute('aria-hidden', isActive ? 'false' : 'true');
				candidate.hidden = !isActive;
			});
			progressItems.forEach(function (item) {
				var isActive = item.getAttribute('data-setup-step') === step.getAttribute('data-step');
				if (isActive) {
					item.setAttribute('aria-current', 'step');
				} else {
					item.removeAttribute('aria-current');
				}
			});

			if (shouldFocus) {
				var heading = step.querySelector('h2');
				if (heading) {
					try {
						heading.focus({ preventScroll: true });
					} catch (error) {
						heading.focus();
					}
				}
			}
		};

		var currentControlsValid = function () {
			if (!activeStep) {
				return true;
			}

			var controls = activeStep.querySelectorAll('input, select, textarea');
			for (var index = 0; index < controls.length; index++) {
				var control = controls[index];
				if (control.disabled || control.type === 'hidden' || control.checkValidity()) {
					continue;
				}
				control.reportValidity();
				return false;
			}
			return true;
		};

		var serverError = root.querySelector('.notice-error, .notice-warning, .cf7aiv-setup-error, [aria-invalid="true"]');
		if (serverError) {
			showAll();
			activeStep = steps[0];
		} else {
			root.classList.add('is-progressive');
			setActive(stepFor('context') || steps[0], false);
		}

		root.addEventListener('click', function (event) {
			if (serverError) {
				return;
			}

			var target = event.target.closest ? event.target.closest('[data-setup-next], [data-setup-back], [data-setup-step]') : null;
			if (!target || !root.contains(target)) {
				return;
			}

			var destination = target.getAttribute('data-setup-next') || target.getAttribute('data-setup-back') || target.getAttribute('data-setup-step');
			var destinationStep = stepFor(destination);
			if (!destinationStep) {
				return;
			}

			var currentIndex = steps.indexOf(activeStep);
			var destinationIndex = steps.indexOf(destinationStep);
			if (destinationIndex > currentIndex && !currentControlsValid()) {
				event.preventDefault();
				return;
			}

			event.preventDefault();
			setActive(destinationStep, true);
		});

		root.addEventListener('invalid', function (event) {
			if (serverError) {
				return;
			}

			var invalidStep = event.target.closest ? event.target.closest('.cf7aiv-setup-step[data-step]') : null;
			if (invalidStep && invalidStep !== activeStep) {
				setActive(invalidStep, true);
			}
		}, true);

		var packSelect = root.querySelector('[data-setup-pack]');
		var guidedPackSelect = root.querySelector('[data-setup-guided-pack]');

		var setPackDisclosures = function (packKey) {
			Array.prototype.forEach.call(root.querySelectorAll('[data-setup-pack-preview], [data-setup-pack-evidence]'), function (panel) {
				var isActive = panel.getAttribute('data-setup-pack-preview') === packKey || panel.getAttribute('data-setup-pack-evidence') === packKey;
				panel.hidden = !isActive;
				panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
			});
		};

		var setSummary = function (key, value) {
			var target = root.querySelector('[data-setup-summary="' + key + '"]');
			if (target) {
				target.textContent = value;
			}
		};

		var compactText = function (value, fallback) {
			var text = String(value || '').replace(/\s+/g, ' ').trim();
			if (text === '') {
				return fallback;
			}
			return text.length > 240 ? text.slice(0, 237) + '...' : text;
		};

		var listText = function (values, fallback) {
			var unique = [];
			values.forEach(function (value) {
				var text = String(value || '').replace(/\s+/g, ' ').trim();
				if (text !== '' && unique.indexOf(text) === -1) {
					unique.push(text);
				}
			});
			if (!unique.length) {
				return fallback;
			}
			var visible = unique.slice(0, 5).join(', ');
			return unique.length > 5 ? visible + ' (+' + (unique.length - 5) + ' more)' : visible;
		};

		var lineText = function (value, fallback) {
			return listText(String(value || '').split(/\r?\n/), fallback);
		};

		var optionText = function (groupName, fallback) {
			var group = root.querySelector('[data-setup-option-group="' + groupName + '"]');
			if (!group) {
				return fallback;
			}
			var labels = [];
			Array.prototype.forEach.call(group.querySelectorAll('input[type="checkbox"]:checked'), function (input) {
				var label = input.closest ? input.closest('label') : null;
				labels.push(label ? label.textContent : input.value);
			});
			return listText(labels, fallback);
		};

		var updateSummary = function () {
			var selectedPack = packSelect ? packSelect.value : (guidedPackSelect ? guidedPackSelect.value : '');
			setPackDisclosures(selectedPack);
			if (packSelect) {
				var selectedOption = packSelect.options[packSelect.selectedIndex];
				setSummary('pack', selectedOption ? selectedOption.textContent.trim() : 'No starter pack selected.');
			}
			var business = root.querySelector('#cf7aiv-business-description');
			var services = root.querySelector('#cf7aiv-services');
			var audiences = root.querySelector('#cf7aiv-audiences');
			var primary = root.querySelector('#cf7aiv-primary');
			setSummary('business_description', compactText(business ? business.value : '', 'No business description entered.'));
			setSummary('services', lineText(services ? services.value : '', 'No services or products entered.'));
			setSummary('audiences', lineText(audiences ? audiences.value : '', 'No audiences entered.'));
			setSummary('primary_definition', compactText(primary ? primary.value : '', 'No primary-opportunity definition entered.'));
			setSummary('exclusion_codes', optionText('exclusion_codes', 'No exclusions selected.'));
			setSummary('ai_whitelist', optionText('ai_whitelist', 'No AI fields selected.'));
		};

		if (packSelect) {
			if (guidedPackSelect) {
				guidedPackSelect.value = packSelect.value;
			}
			packSelect.addEventListener('change', function () {
				if (guidedPackSelect) {
					guidedPackSelect.value = packSelect.value;
				}
				updateSummary();
			});
		}
		if (guidedPackSelect) {
			guidedPackSelect.addEventListener('change', function () {
				if (packSelect) {
					packSelect.value = guidedPackSelect.value;
				}
				updateSummary();
			});
		}

		['#cf7aiv-business-description', '#cf7aiv-services', '#cf7aiv-audiences', '#cf7aiv-primary'].forEach(function (selector) {
			var control = root.querySelector(selector);
			if (control) {
				control.addEventListener('input', updateSummary);
				control.addEventListener('change', updateSummary);
			}
		});
		['exclusion_codes', 'ai_whitelist'].forEach(function (groupName) {
			var group = root.querySelector('[data-setup-option-group="' + groupName + '"]');
			if (group) {
				Array.prototype.forEach.call(group.querySelectorAll('input[type="checkbox"]'), function (input) {
					input.addEventListener('change', updateSummary);
				});
			}
		});
		updateSummary();
	});
}());

(function () {
	'use strict';

	var roots = document.querySelectorAll('.cf7aiv-integrations-page');

	Array.prototype.forEach.call(roots, function (root) {
		var tabLinks = Array.prototype.slice.call(root.querySelectorAll('.cf7aiv-integration-tabs [data-tab]'));
		var panels = Array.prototype.slice.call(root.querySelectorAll('.cf7aiv-integration-tab-panel[data-tab]'));
		var form = root.querySelector('[data-settings-form]');
		var saveBars = Array.prototype.slice.call(root.querySelectorAll('.cf7aiv-save-bar'));
		var returnTab = root.querySelector('[data-integration-return-tab]');
		var activeTab = root.getAttribute('data-active-tab') || 'provider';
		var tabNames = tabLinks.map(function (link) {
			return link.getAttribute('data-tab');
		});

		if (!tabLinks.length) {
			return;
		}

		var validTab = function (tab) {
			return tabNames.indexOf(tab) !== -1 ? tab : tabNames[0];
		};

		var queryTab = function () {
			var match = window.location.search.match(/[?&]tab=([^&]+)/);
			if (!match) {
				return null;
			}
			try {
				return decodeURIComponent(match[1]);
			} catch (error) {
				return null;
			}
		};

		var setActiveTab = function (tab, shouldFocus) {
			activeTab = validTab(tab);
			tabLinks.forEach(function (link) {
				var isActive = link.getAttribute('data-tab') === activeTab;
				link.classList.toggle('is-active', isActive);
				link.setAttribute('aria-selected', isActive ? 'true' : 'false');
			});
			panels.forEach(function (panel) {
				var isActive = panel.getAttribute('data-tab') === activeTab;
				panel.classList.toggle('is-active', isActive);
				panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
				panel.hidden = !isActive;
			});
			if (returnTab) {
				returnTab.value = activeTab;
			}
			if (shouldFocus) {
				var activeLink = tabLinks.find(function (link) {
					return link.getAttribute('data-tab') === activeTab;
				});
				if (activeLink) {
					try {
						activeLink.focus({ preventScroll: true });
					} catch (error) {
						activeLink.focus();
					}
				}
			}
		};

		var setProvider = function (provider) {
			var selected = provider === 'openai' ? 'openai' : 'deepseek';
			Array.prototype.forEach.call(root.querySelectorAll('[data-provider-panel="true"]'), function (panel) {
				var isActive = panel.getAttribute('data-provider') === selected;
				panel.classList.toggle('is-active', isActive);
				panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
				panel.disabled = !isActive;
				panel.hidden = !isActive;
			});
		};

		var setSaveState = function (state) {
			var dirty = state !== 'saved';
			var statusText = {
				saved: 'Saved',
				unsaved: 'Unsaved changes',
				saving: 'Saving',
				failed: 'Save failed'
			}[state] || 'Saved';
			saveBars.forEach(function (bar) {
				bar.setAttribute('data-dirty', dirty ? 'true' : 'false');
				bar.setAttribute('data-save-state', state);
				var status = bar.querySelector('[data-save-status]');
				if (status) {
					status.setAttribute('role', 'status');
					status.setAttribute('aria-live', 'polite');
					status.setAttribute('aria-atomic', 'true');
					status.textContent = statusText;
				}
				Array.prototype.forEach.call(bar.querySelectorAll('[name="cf7aiv-save"]'), function (button) {
					button.disabled = !dirty;
				});
				Array.prototype.forEach.call(bar.querySelectorAll('[name="cf7aiv-discard"]'), function (button) {
					button.disabled = !dirty;
				});
			});
		};

		var controls = function () {
			if (!form) {
				return [];
			}
			return Array.prototype.filter.call(form.querySelectorAll('input, select, textarea'), function (control) {
				return control.getAttribute('data-integration-return-tab') === null;
			});
		};

		var snapshot = [];
		var takeSnapshot = function () {
			snapshot = controls().map(function (control) {
				return {
					control: control,
					value: control.value,
					checked: control.checked
				};
			});
		};

		var hasChanges = function () {
			return snapshot.some(function (item) {
				return item.control.value !== item.value || item.control.checked !== item.checked;
			});
		};

		setActiveTab(validTab(queryTab() || activeTab), false);
		var providerSelector = root.querySelector('[data-provider-selector]');
		if (providerSelector) {
			setProvider(providerSelector.value);
			providerSelector.addEventListener('change', function () {
				setProvider(providerSelector.value);
			});
		}

		tabLinks.forEach(function (link) {
			link.addEventListener('click', function (event) {
				var tab = validTab(link.getAttribute('data-tab'));
				event.preventDefault();
				setActiveTab(tab, true);
				if (window.history && window.history.replaceState) {
					window.history.replaceState({}, '', link.href);
				}
			});
		});

		if (!form) {
			return;
		}

		takeSnapshot();
		setSaveState(root.querySelector('.cf7aiv-save-bar[data-save-state="failed"]') ? 'failed' : 'saved');
		form.addEventListener('input', function () {
			setSaveState(hasChanges() ? 'unsaved' : 'saved');
		});
		form.addEventListener('change', function () {
			setSaveState(hasChanges() ? 'unsaved' : 'saved');
		});
		form.addEventListener('submit', function () {
			setSaveState('saving');
		});

		root.addEventListener('click', function (event) {
			var discard = event.target.closest ? event.target.closest('[name="cf7aiv-discard"]') : null;
			if (!discard || !root.contains(discard) || !hasChanges()) {
				return;
			}
			if (!window.confirm('Discard unsaved integration changes?')) {
				event.preventDefault();
				return;
			}
			snapshot.forEach(function (item) {
				item.control.value = item.value;
				item.control.checked = item.checked;
			});
			if (providerSelector) {
				setProvider(providerSelector.value);
			}
			setSaveState('saved');
		});

		window.addEventListener('popstate', function () {
			setActiveTab(validTab(queryTab() || activeTab), false);
		});
	});
}());

/*
 * Show that a slow administration action is running.
 *
 * Several actions call an AI provider or Google synchronously and can take tens
 * of seconds. They submit an ordinary form, so the page simply sits there with
 * no cursor change, no disabled button and no message. Reported as "clicking
 * seems not to do anything" - the operator pressed the button repeatedly,
 * queueing duplicate provider calls, and only learned it had been working when
 * an error appeared much later.
 *
 * This only reflects work that is genuinely in progress; it never claims
 * success. Kept separate from the tabbed Integrations enhancement above so it
 * applies to every plugin screen, and written so a page without these forms is
 * unaffected.
 */
(function () {
	'use strict';

	var SLOW_ACTIONS = [
		'cf7aiv_setup_scan',
		'cf7aiv_validate_ga4',
		'cf7aiv_test_microsoft',
		'cf7aiv_setup_suggestion',
		'cf7aiv_backfill',
		'cf7aiv_test_provider'
	];

	var BUSY_LABEL = {
		cf7aiv_setup_scan: 'Analysing, this can take up to a minute',
		cf7aiv_validate_ga4: 'Validating with GA4',
		cf7aiv_test_microsoft: 'Sending the test event',
		cf7aiv_setup_suggestion: 'Applying',
		cf7aiv_backfill: 'Scheduling',
		cf7aiv_test_provider: 'Contacting the AI provider'
	};

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || form.tagName !== 'FORM' || event.defaultPrevented) { return; }
		var action = form.querySelector('input[name="action"]');
		if (!action || SLOW_ACTIONS.indexOf(action.value) === -1) { return; }

		// A button can sit outside the form it submits (form="…"), as the AI
		// connection test's does, so look for it by that too.
		var button = event.submitter
			|| form.querySelector('button[type="submit"], input[type="submit"]')
			|| (form.id ? document.querySelector('[form="' + form.id + '"]') : null);
		if (!button || button.getAttribute('data-cf7aiv-busy') === 'true') { return; }

		// The label is replaced rather than the button disabled before submit,
		// because a disabled control is omitted from the submitted data in some
		// browsers and the action would lose its own button value.
		button.setAttribute('data-cf7aiv-busy', 'true');
		button.setAttribute('aria-busy', 'true');
		var label = BUSY_LABEL[action.value] || 'Working';
		if ('textContent' in button) { button.textContent = label + '…'; }
		else { button.value = label + '…'; }

		var status = document.createElement('p');
		status.className = 'cf7aiv-busy-status';
		status.setAttribute('role', 'status');
		status.setAttribute('aria-live', 'polite');
		status.textContent = label + '. This page will update when it finishes.';
		if (button.parentNode) { button.parentNode.appendChild(status); }

		window.setTimeout(function () { button.disabled = true; }, 0);
	}, true);

	/*
	 * Switching provider in the accuracy test switches to that provider's saved
	 * model, unless the model has been typed in by hand.
	 */
	document.addEventListener('change', function (event) {
		var provider = event.target;
		if (!provider || !provider.hasAttribute || !provider.hasAttribute('data-cf7aiv-accuracy-provider')) { return; }
		var model = provider.form ? provider.form.querySelector('[data-cf7aiv-accuracy-model]') : null;
		if (!model) { return; }
		var saved = Array.prototype.map.call(provider.options, function (option) { return option.getAttribute('data-model') || ''; });
		var chosen = provider.options[provider.selectedIndex];
		if (chosen && (model.value === '' || saved.indexOf(model.value) !== -1)) {
			model.value = chosen.getAttribute('data-model') || '';
		}
	});

	/*
	 * Each tier names its outcome, so choosing a corrected tier selects that
	 * outcome. The outcome list stays editable afterwards.
	 */
	document.addEventListener('change', function (event) {
		var tier = event.target;
		if (!tier || !tier.hasAttribute || !tier.hasAttribute('data-cf7aiv-tier-select')) { return; }
		var form = tier.form;
		var outcome = form ? form.querySelector('[data-cf7aiv-outcome-select]') : null;
		var chosen = tier.options[tier.selectedIndex];
		var value = chosen ? chosen.getAttribute('data-outcome') : '';
		if (outcome && value) { outcome.value = value; }
	});
}());
