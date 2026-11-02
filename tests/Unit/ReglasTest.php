<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Reglas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Desplazamiento mínimo según de dónde se sale (regla b del enunciado; R1 y R2 del análisis). */
final class ReglasTest extends TestCase
{
    /** @return array<string, array{?int, int, int}> */
    public static function casos(): array
    {
        return [
            'desde la sede (R2)' => [null, 1, 0],
            'misma obra (R1)' => [3, 3, 0],
            'obras diferentes (regla b)' => [1, 2, 30],
        ];
    }

    #[DataProvider('casos')]
    #[TestDox('Desplazamiento mínimo: $_dataName')]
    public function testDesplazamientoMinimo(?int $obraAnterior, int $obra, int $minimo): void
    {
        self::assertSame($minimo, Reglas::desplazamientoMinimo($obraAnterior, $obra));
    }
}
