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
