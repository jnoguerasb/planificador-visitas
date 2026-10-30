<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Horas;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HorasTest extends TestCase
{
    /** @return array<string, array{string, int}> */
    public static function horasValidas(): array
    {
        return [
            'medianoche' => ['00:00', 0],
            'inicio de jornada' => ['08:00', 480],
            'ejemplo del análisis' => ['10:30', 630],
            'fin de jornada' => ['17:00', 1020],
            'último minuto del día' => ['23:59', 1439],
        ];
    }

    #[DataProvider('horasValidas')]
    public function testConvierteHoraAMinutosYVuelta(string $texto, int $minutos): void
    {
        self::assertTrue(Horas::esValida($texto));
        self::assertSame($minutos, Horas::aMinutos($texto));
        self::assertSame($texto, Horas::aTexto($minutos));
    }

    /** @return array<string, array{mixed}> */
    public static function horasNoValidas(): array
    {
        return [
            'vacía' => [''],
            'sin cero inicial' => ['8:00'],
            'hora 24' => ['24:00'],
            'minuto 60' => ['10:60'],
            'con segundos' => ['10:30:00'],
            'con espacios' => [' 10:30'],
            'separador distinto' => ['10.30'],
            'salto de línea final' => ["10:30\n"],
            'número' => [630],
            'nulo' => [null],
        ];
    }

    #[DataProvider('horasNoValidas')]
    public function testRechazaHorasConFormatoIncorrecto(mixed $valor): void
    {
        self::assertFalse(Horas::esValida($valor));
    }

    public function testAMinutosLanzaExcepcionConHoraIncorrecta(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Horas::aMinutos('25:00');
    }

    public function testATextoLanzaExcepcionFueraDelDia(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Horas::aTexto(1440);
    }

    public function testATextoLanzaExcepcionConMinutosNegativos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Horas::aTexto(-1);
    }
}
