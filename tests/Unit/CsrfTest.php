<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Csrf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testGeneraTokensDe64HexadecimalesDistintos(): void
    {
        $a = Csrf::generar();
        $b = Csrf::generar();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $a);
        self::assertNotSame($a, $b);
    }

    public function testTokenValido(): void
    {
        $token = Csrf::generar();

        self::assertTrue(Csrf::esValido($token, $token));
    }

    /** @return array<string, array{?string, mixed}> */
    public static function tokensNoValidos(): array
    {
        $token = str_repeat('a', 64);

        return [
            'ausente' => [$token, null],
            'vacío' => [$token, ''],
            'alterado' => [$token, str_repeat('a', 63) . 'b'],
            'más corto' => [$token, str_repeat('a', 63)],
            'con espacios' => [$token, " {$token}"],
            'mayúsculas' => [$token, strtoupper($token)],
            'no es texto' => [$token, ['a']],
            'sin token en la sesión' => [null, $token],
            'token de sesión vacío' => ['', ''],
        ];
    }

    #[DataProvider('tokensNoValidos')]
    public function testTokenNoValido(?string $esperado, mixed $recibido): void
    {
        self::assertFalse(Csrf::esValido($esperado, $recibido));
    }
}
