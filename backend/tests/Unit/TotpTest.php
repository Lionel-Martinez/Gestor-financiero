<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Security\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    // Secreto de los vectores de prueba de RFC 6238: "12345678901234567890"
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_vectores_rfc_6238_sha1(): void
    {
        $this->assertSame('287082', Totp::code(self::SECRET, 59));
        $this->assertSame('081804', Totp::code(self::SECRET, 1111111109));
        $this->assertSame('050471', Totp::code(self::SECRET, 1111111111));
        $this->assertSame('005924', Totp::code(self::SECRET, 1234567890));
        $this->assertSame('279037', Totp::code(self::SECRET, 2000000000));
        $this->assertSame('353130', Totp::code(self::SECRET, 20000000000));
    }

    public function test_verify_con_ventana_y_rechazo(): void
    {
        $this->assertTrue(Totp::verify(self::SECRET, '287082', 59));
        $this->assertTrue(Totp::verify(self::SECRET, '287 082', 59 + 30));   // un paso después, con espacios
        $this->assertFalse(Totp::verify(self::SECRET, '287082', 59 + 120));  // fuera de ventana
        $this->assertFalse(Totp::verify(self::SECRET, '12345', 59));
        $this->assertFalse(Totp::verify(self::SECRET, 'abcdef', 59));
    }

    public function test_base32_ida_y_vuelta(): void
    {
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::SECRET));
        $raw = random_bytes(20);
        $this->assertSame($raw, Totp::base32Decode(Totp::base32Encode($raw)));
    }

    public function test_secreto_generado_es_valido(): void
    {
        $s = Totp::generateSecret();
        $this->assertSame(32, strlen($s));
        $this->assertTrue(Totp::verify($s, Totp::code($s, time())));
    }

    public function test_codigos_de_recuperacion(): void
    {
        $codes = Totp::generateRecoveryCodes(8);
        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        $this->assertTrue((bool) preg_match('/^[0-9A-F]{5}-[0-9A-F]{5}$/', $codes[0]));
        $this->assertSame(Totp::hashRecoveryCode($codes[0]), Totp::hashRecoveryCode(strtolower(str_replace('-', ' ', $codes[0]))));
    }

    public function test_uri_de_aprovisionamiento(): void
    {
        $uri = Totp::provisioningUri('ABC', 'lionel@example.com');
        $this->assertStringContainsString('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=ABC', $uri);
    }
}
