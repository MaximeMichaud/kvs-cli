<?php

/**
 * PHPUnit bootstrap file
 *
 * Loads utils.php so utility functions are available in all tests.
 * Loads TestHelper.php so test utilities are available.
 * This is needed because tests instantiate commands directly without
 * going through Application::__construct().
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/utils.php';
require_once __DIR__ . '/TestHelper.php';
require_once __DIR__ . '/BaseTestCase.php';
