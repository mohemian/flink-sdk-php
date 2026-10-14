<?php

namespace Mohemian\Flink\Contracts;

use Firebase\JWT\Key;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Exceptions\KeysUnavailable;

/** The public keys FLINK signs its assertions with. */
interface KeySet
{
    /**
     * @throws InvalidSignature for an unknown kid or a key that is not RS256
     * @throws KeysUnavailable when the keys cannot be fetched
     */
    public function key(string $kid): Key;

    /** Drop what is cached, so the next lookup fetches the keys again. */
    public function forget(): void;
}
