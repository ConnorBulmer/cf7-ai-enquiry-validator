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
		var guidedPackInput = root.querySelector('[data-setup-guided-pack]');

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
			if (guidedPackInput) {
				guidedPackInput.value = packSelect.value;
			}
			packSelect.addEventListener('change', function () {
				if (guidedPackInput) {
					guidedPackInput.value = packSelect.value;
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
