<?php

declare(strict_types=1);

namespace Mohemian\Flink\Exceptions;

/**
 * A token FLINK did not issue, or did not issue for you. Catch this to refuse any of them; catch a
 * subclass to tell a forgery from a mismatch. Missing configuration is not one of these.
 */
abstract class FlinkException extends \RuntimeException {}
