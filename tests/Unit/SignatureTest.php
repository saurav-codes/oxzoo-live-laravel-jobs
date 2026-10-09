<?php

namespace Tests\Unit;

use App\Zoo\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private const KEY = 'zoo-test-key-0123456789abcdef';

    public function test_design_vectors(): void
    {
        $body = '{"sku":"ZOO-1"}';
        $this->assertSame('cc2860a77ea231854ea58f9cb05f3217059f80a8e95d7b69a204293ae4f3a444', hash('sha256', $body));
        $this->assertSame('50c22839fe6a06cb51a9fd25167d9e457eb0b5ee63ce696f4c5428a6b9271da1',
            Signature::sign(self::KEY, 1760000000, 'POST', '/api/items?x=1', $body));
        $this->assertSame('9a404bebaa32497c5ed39ef8990e8466428f8023d6aa9f5acc94f16fb7670ecb',
            Signature::sign(self::KEY, 1760000000, 'get', '/_zoo/verify', ''));
        $this->assertSame('915a', Signature::fp(self::KEY));
    }

    public function test_header_shape(): void
    {
        $this->assertSame('t=1760000000,caller=laravel-jobs,sig=9a404bebaa32497c5ed39ef8990e8466428f8023d6aa9f5acc94f16fb7670ecb',
            Signature::header(self::KEY, 'laravel-jobs', 'GET', '/_zoo/verify', '', 1760000000));
    }
}
