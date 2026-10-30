<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\BaseDatosTest;
use PHPUnit\Framework\Attributes\DataProvider;

final class SeedTest extends BaseDatosTest
{
    /** @return array<string, array{string, int}> */
    public static function filasPorTabla(): array
    {
        return [
            'sede' => ['SELECT COUNT(*) FROM sede', 1],
            'técnicos' => ['SELECT COUNT(*) FROM tecnicos', 4],
            'obras' => ['SELECT COUNT(*) FROM obras', 6],
            'visitas' => ['SELECT COUNT(*) FROM visitas', 12],
            'usuarios (los crea el script, no el seed)' => ['SELECT COUNT(*) FROM usuarios', 0],
        ];
    }

    #[DataProvider('filasPorTabla')]
    public function testElSeedCargaElNumeroEsperadoDeFilas(string $sql, int $esperadas): void
    {
        self::assertSame($esperadas, $this->valor($sql));
    }

    public function testLasObrasEstanEnCuatroMunicipios(): void
    {
        self::assertSame(4, $this->valor('SELECT COUNT(DISTINCT municipio) FROM obras'));
    }

    public function testHayUnaCompletadaConComentarioYUnaCanceladaConMotivo(): void
    {
        self::assertSame(1, $this->valor("SELECT COUNT(*) FROM visitas WHERE estado = 'Completada' AND comentario_cierre <> ''"));
        self::assertSame(1, $this->valor("SELECT COUNT(*) FROM visitas WHERE estado = 'Cancelada' AND motivo_cancelacion <> ''"));
    }

    public function testIncluyeCasosLimiteDeDesplazamiento(): void
    {
        self::assertGreaterThan(0, $this->valor('SELECT COUNT(*) FROM visitas WHERE desplazamiento_min = 30'));
        self::assertGreaterThan(0, $this->valor('SELECT COUNT(*) FROM visitas WHERE desplazamiento_min = 240'));
        self::assertGreaterThan(0, $this->valor('SELECT COUNT(*) FROM visitas WHERE revisar_desplazamiento'));
        // Acaba a las 17:00 justas
        self::assertGreaterThan(0, $this->valor('SELECT COUNT(*) FROM visitas WHERE EXTRACT(EPOCH FROM hora_inicio) / 60 + duracion_min = 1020'));
    }

    public function testLasFechasSonRelativasAlDiaActual(): void
    {
        // De ayer (-1) a pasado mañana (+2)
        self::assertSame(-1, $this->valor('SELECT MIN(fecha - CURRENT_DATE) FROM visitas'));
        self::assertSame(2, $this->valor('SELECT MAX(fecha - CURRENT_DATE) FROM visitas'));
    }

    public function testLasSecuenciasContinuanDespuesDelUltimoId(): void
    {
        $id = $this->valor(
            "INSERT INTO visitas (tecnico_id, obra_id, fecha, hora_inicio, duracion_min, origen_tipo)
             VALUES (4, 1, CURRENT_DATE + 30, '10:00', 60, 'SEDE') RETURNING id"
        );
        self::assertSame(13, $id);
    }
}
