<?php

namespace Mohemian\Flink\Contracts;

use Firebase\JWT\Key;
use Mohemian\Flink\Exceptions\InvalidSignature;

/** The public keys FLINK signs its assertions with. */
interface KeySet
{
    /** @throws InvalidSignature for an unknown kid or a key that is not RS256 */
    public function key(string $kid): Key;

    /** Drop what is cached, so the next lookup fetches the keys again. */
    public function forget(): void;
}
