<?php

declare(strict_types=1);

add_filter('pre_wp_mail', static function (mixed $return, array $attributes): mixed {
	$mode = isset($_POST['test-mail-mode']) && is_scalar($_POST['test-mail-mode']) ? (string) $_POST['test-mail-mode'] : '';
	if ($mode === 'success') { return true; }
	if ($mode === 'fail') { return false; }
	return $return;
}, 10, 2);
