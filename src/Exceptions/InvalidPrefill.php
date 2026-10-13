<?php

namespace Mohemian\Flink\Exceptions;

/** The prefill JWT is not one FLINK issued for this tenant, journey and page, or it has expired. */
class InvalidPrefill extends \RuntimeException
{
}
