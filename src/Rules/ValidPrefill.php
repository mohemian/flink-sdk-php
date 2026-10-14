<?php

namespace Mohemian\Flink\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Mohemian\Flink\Assertions\PrefillAssertion;
use Mohemian\Flink\Exceptions\FlinkException;
use Mohemian\Flink\Flink;

/**
 * `'prefill_jwt' => ['required', $rule = new ValidPrefill]`, then `$rule->assertion`. The reason a
 * prefill was refused is not shown to the user; dispatch events (`flink.events`) to log it.
 */
final class ValidPrefill implements ValidationRule
{
    public ?PrefillAssertion $assertion = null;

    /** @param string|null $reference defaults to this session's */
    public function __construct(private readonly ?string $reference = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->assertion = null;
        try {
            $this->assertion = app(Flink::class)->prefill($value, $this->reference);
        } catch (FlinkException) {
            $fail('The :attribute is not a valid FLINK prefill.');
        }
    }
}
