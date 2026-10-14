<?php

namespace Mohemian\Flink\Facades;

use Illuminate\Support\Facades\Facade;
use Mohemian\Flink\Contracts\KeySet;
use Mohemian\Flink\Testing\FlinkFake;

/**
 * @method static \Mohemian\Flink\Assertions\PrefillAssertion prefill(mixed $jwt, ?string $reference = null)
 * @method static \Mohemian\Flink\Assertions\ResultsAssertion results(mixed $jwt)
 * @method static \Mohemian\Flink\Assertions\Assertion verify(mixed $jwt, string $type, array<string, mixed> $expected = [])
 * @method static string reference()
 * @method static string|null currentReference()
 * @method static void forgetReference()
 *
 * @see \Mohemian\Flink\Flink
 */
final class Flink extends Facade
{
    /**
     * Verify against a key generated for the test instead of FLINK's, and get a fake that signs
     * assertions with it. Tokens signed by FLINK's real keys no longer verify.
     */
    public static function fake(): FlinkFake
    {
        $app = self::getFacadeApplication() ?? throw new \LogicException('Flink::fake() needs a booted application');
        $fake = new FlinkFake($app);
        $app->instance(KeySet::class, $fake->keys);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Mohemian\Flink\Flink::class;
    }
}
