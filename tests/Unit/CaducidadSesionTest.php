<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** La sesión caduca tras 30 min sin actividad o a las 8 h de iniciarla, lo que pase antes. */
final class CaducidadSesionTest extends TestCase
{
    private const INICIO = 1_900_000_000;

    /** @return array<string, array{int, int, bool}> Minutos desde el inicio hasta la última actividad y hasta ahora. */
    public static function casos(): array
    {
        return [
            'recién iniciada' => [0, 0, false],
            '30 min justos sin actividad' => [0, 30, false],
            '31 min sin actividad' => [0, 31, true],
            'activa cada 20 min durante 7 h 59 min' => [459, 479, false],
            '8 h justas' => [470, 480, false],
            'más de 8 h aunque haya actividad' => [480, 481, true],
        ];
    }

    #[DataProvider('casos')]
    public function testCaducidad(int $ultimaActividad, int $ahora, bool $caducada): void
    {
        self::assertSame($caducada, Auth::caducada(self::INICIO, self::INICIO + $ultimaActividad * 60, self::INICIO + $ahora * 60));
    }
}
