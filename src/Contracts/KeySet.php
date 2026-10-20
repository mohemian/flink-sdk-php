<?php

declare(strict_types=1);

namespace Mohemian\Flink\Contracts;

use Firebase\JWT\Key;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Exceptions\KeysUnavailable;

/** The public keys FLINK signs its assertions with. */
interface KeySet
{
    /**
     * @throws InvalidSignature for an unknown kid or a key of another algorithm than `$alg`
     * @throws KeysUnavailable when the keys cannot be fetched
     */
    public function key(string $kid, string $alg): Key;

    /** Drop what is cached, so the next lookup fetches the keys again. */
    public function forget(): void;
}
