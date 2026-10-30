<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Visita;
use PHPUnit\Framework\TestCase;

final class VisitaTest extends TestCase
{
    public function testFinYSalidaSeCalculanEnMinutos(): void
    {
        $visita = new Visita(1, 1, 1, 'Obra A', '2026-11-03', 600, 90, 40);

        self::assertSame(690, $visita->fin());
        self::assertSame(560, $visita->salida());
    }

    public function testElOrigenEsLaSedeSiNoSaleDeOtraVisita(): void
    {
        self::assertSame(Visita::ORIGEN_SEDE, (new Visita(1, 1, 1, 'Obra A', '2026-11-03', 600, 60, 30))->origenTipo());
        self::assertSame(Visita::ORIGEN_VISITA, (new Visita(2, 1, 1, 'Obra A', '2026-11-03', 720, 60, 30, Visita::PROGRAMADA, 1))->origenTipo());
    }

    public function testSoloLasCanceladasDejanDeEstarActivas(): void
    {
        $visita = fn (string $estado) => new Visita(1, 1, 1, 'Obra A', '2026-11-03', 600, 60, 30, $estado);

        self::assertTrue($visita(Visita::PROGRAMADA)->estaActiva());
        self::assertTrue($visita(Visita::COMPLETADA)->estaActiva());
        self::assertFalse($visita(Visita::CANCELADA)->estaActiva());
    }
}
