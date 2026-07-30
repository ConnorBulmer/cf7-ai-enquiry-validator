<?php

declare(strict_types=1);

namespace CF7AIV;

/** Stable public lifecycle action names for extension authors. */
final class Hooks {
	public const AFTER_CAPTURE = 'cf7aiv_after_capture';
	public const AFTER_CLASSIFICATION = 'cf7aiv_after_classification';
	public const AFTER_REVIEW = 'cf7aiv_after_review';
	public const AFTER_DELIVERY = 'cf7aiv_after_delivery';
	public const AFTER_SETUP_SUGGESTIONS = 'cf7aiv_after_setup_suggestions';
	public const AFTER_CALIBRATION_SCHEDULED = 'cf7aiv_after_calibration_scheduled';
}
