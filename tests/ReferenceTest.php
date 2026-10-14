<?php

use Illuminate\Support\Facades\Blade;
use Mohemian\Flink\Facades\Flink;

it('issues one reference per session and keeps it', function () {
    $reference = Flink::reference();

    expect($reference)->toHaveLength(40)
        ->and(Flink::reference())->toBe($reference)
        ->and(Flink::currentReference())->toBe($reference)
        ->and(session('flink.reference'))->toBe($reference);
});

it('rotates the reference once forgotten', function () {
    $first = Flink::reference();
    Flink::forgetReference();

    expect(Flink::currentReference())->toBeNull()
        ->and(Flink::reference())->not->toBe($first);
});

it('stores the reference under the configured session key', function () {
    config(['flink.prefill.session_key' => 'rp.flink']);

    expect(session('rp.flink'))->toBeNull()
        ->and(Flink::reference())->toBe(session('rp.flink'));
});

it('renders the reference with @flinkReference', function () {
    session(['flink.reference' => 'a"b<c']);

    expect(Blade::render("<x data-ref=\"@flinkReference\">"))->toBe('<x data-ref="a&quot;b&lt;c">');
});
