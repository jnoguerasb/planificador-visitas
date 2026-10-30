<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTest;
use PHPUnit\Framework\Attributes\TestDox;

/** GET /api/tecnicos y GET /api/obras: solo el planificador, para los desplegables. */
final class CatalogoApiTest extends ApiTest
{
    #[TestDox('GET /api/tecnicos: técnicos activos por nombre')]
    public function testTecnicos(): void
    {
        $this->admin()->exec('UPDATE tecnicos SET activo = FALSE WHERE id = 4');
        $this->entrar('planificador@test.local');

        $respuesta = $this->peticion('GET', '/api/tecnicos');

        self::assertSame(200, $respuesta['estado'], $respuesta['cuerpo']);
        self::assertSame(['tecnicos' => [
            ['id' => 1, 'nombre' => 'Ana Puig'],
            ['id' => 2, 'nombre' => 'Bernat Soler'],
            ['id' => 3, 'nombre' => 'Carla Vidal'],
        ]], $respuesta['json']);
        $this->admin()->exec('UPDATE tecnicos SET activo = TRUE WHERE id = 4');
    }

    #[TestDox('GET /api/obras: obras con dirección y municipio, por nombre')]
    public function testObras(): void
    {
        $this->entrar('planificador@test.local');

        $respuesta = $this->peticion('GET', '/api/obras');

        self::assertSame(200, $respuesta['estado']);
        self::assertCount(6, $respuesta['json']['obras']);
        self::assertSame(
            ['id' => 6, 'nombre' => 'Ampliación ambulatorio', 'direccion' => 'Carrer de Sant Jaume, 55', 'municipio' => 'Rubí'],
            $respuesta['json']['obras'][0]
        );
    }

    #[TestDox('Catálogo sin sesión: 401; como técnico: 403; con parámetros no esperados: 422')]
    public function testCatalogoProtegido(): void
    {
        foreach (['/api/tecnicos', '/api/obras'] as $ruta) {
            $this->cookies = [];
            self::assertSame(401, $this->peticion('GET', $ruta)['estado'], $ruta);

            $this->entrar('ana@test.local');
            self::assertSame(403, $this->peticion('GET', $ruta)['estado'], $ruta);

            $this->cookies = [];
            $this->entrar('planificador@test.local');
            $extra = $this->peticion('GET', $ruta . '?orden=nombre');
            self::assertSame(422, $extra['estado'], $ruta);
            self::assertSame('CAMPOS', $extra['json']['errores'][0]['regla']);
        }
    }
}
