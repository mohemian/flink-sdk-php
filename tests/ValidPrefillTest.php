<?php

use Illuminate\Support\Facades\Validator;
use Mohemian\Flink\Rules\ValidPrefill;
use Mohemian\Flink\Tests\TestCase;

it('passes a valid prefill and exposes the verified assertion', function (): void {
    session(['flink.reference' => TestCase::REF]);
    $rule = new ValidPrefill;

    $validator = Validator::make(['prefill_jwt' => $this->jwt([])], ['prefill_jwt' => ['required', $rule]]);

    expect($validator->passes())->toBeTrue()
        ->and($rule->assertion?->data)->toBe(['givenName' => 'Erika']);
});

it('fails an invalid prefill without saying why', function (mixed $value): void {
    session(['flink.reference' => TestCase::REF]);
    $rule = new ValidPrefill;

    $validator = Validator::make(['prefill_jwt' => $value], ['prefill_jwt' => [$rule]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('prefill_jwt'))->toBe('The prefill jwt is not a valid FLINK prefill.')
        ->and($rule->assertion)->toBeNull();
})->with([
    'another session' => fn () => $this->jwt(['reference' => 'other']),
    'garbage' => ['x.y.z'],
    'an array' => [['a']],
]);

it('checks against the reference it is given', function (): void {
    $validator = Validator::make(['p' => $this->jwt(['reference' => 'given'])], ['p' => [new ValidPrefill('given')]]);

    expect($validator->passes())->toBeTrue();
});

it('forgets the previous assertion when reused', function (): void {
    session(['flink.reference' => TestCase::REF]);
    $rule = new ValidPrefill;

    Validator::make(['p' => $this->jwt([])], ['p' => [$rule]])->passes();
    Validator::make(['p' => 'x.y.z'], ['p' => [$rule]])->passes();

    expect($rule->assertion)->toBeNull();
});
