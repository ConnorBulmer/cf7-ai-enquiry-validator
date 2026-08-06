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
		var scanPackInputs = Array.prototype.slice.call(root.querySelectorAll('[data-setup-scan-pack]'));
		if (packSelect) {
			packSelect.addEventListener('change', function () {
				scanPackInputs.forEach(function (input) {
					input.value = packSelect.value;
				});
			});
		}
	});
}());
