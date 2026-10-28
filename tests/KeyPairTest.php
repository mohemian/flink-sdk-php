<?php

use Mohemian\Flink\Testing\KeyPair;

it('always serves P-256 coordinates as exactly 32 bytes', function (): void {
    // OpenSSL drops leading zero bytes, so about 1 key in 64 has a short x or y: 2000 keys make
    // missing one (and so passing without the padding) about a 1e-14 chance.
    for ($i = 0; $i < 2000; $i++) {
        $jwk = KeyPair::generate('e'.$i, 'ES256')['jwk'];

        foreach (['x', 'y'] as $coordinate) {
            expect(strlen(base64_decode(strtr($jwk[$coordinate], '-_', '+/'))))->toBe(32);
        }
    }
});
