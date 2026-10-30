<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CatalogoRepository;
use App\Tests\Support\ServicioTest;
use App\Usuario;
use App\UsuarioRepository;

/** CatalogoRepository, UsuarioRepository y la configuración de la conexión. */
final class RepositoriosTest extends ServicioTest
{
    public function testLaConexionUsaLaZonaHorariaDeMadrid(): void
    {
        self::assertSame('Europe/Madrid', $this->app()->query('SHOW TIME ZONE')->fetchColumn());
    }

    public function testTecnicosActivosOrdenadosPorNombre(): void
    {
        $this->admin()->exec('UPDATE tecnicos SET activo = FALSE WHERE id = 3');

        self::assertSame(
            [1 => 'Ana Puig', 2 => 'Bernat Soler', 4 => 'David Roca'],
            (new CatalogoRepository($this->app()))->tecnicosActivos()
        );
    }

    public function testNombreDeCualquierTecnicoAunqueEsteInactivo(): void
    {
        $this->admin()->exec('UPDATE tecnicos SET activo = FALSE WHERE id = 3');
        $catalogo = new CatalogoRepository($this->app());

        self::assertSame('Carla Vidal', $catalogo->nombreTecnico(3));
        self::assertNull($catalogo->nombreTecnico(99));
    }

    public function testObrasConDireccionYMunicipioOrdenadasPorNombre(): void
    {
        $obras = (new CatalogoRepository($this->app()))->obras();

        self::assertCount(6, $obras);
        self::assertSame(['id' => 6, 'nombre' => 'Ampliación ambulatorio', 'direccion' => 'Carrer de Sant Jaume, 55', 'municipio' => 'Rubí'], $obras[0]);
        $nombres = array_column($obras, 'nombre');
        $ordenados = $nombres;
        sort($ordenados);
        self::assertSame($ordenados, $nombres);
    }

    public function testNombresDeObrasPorId(): void
    {
        $nombres = (new CatalogoRepository($this->app()))->nombresObras();

        self::assertSame('Escuela Can Puiggener', $nombres[1]);
        self::assertSame([1, 2, 3, 4, 5, 6], array_keys($nombres));
    }

    public function testSede(): void
    {
        self::assertSame(
            ['nombre' => 'Sede central', 'direccion' => 'Carrer de la Indústria, 25', 'municipio' => 'Sabadell'],
            (new CatalogoRepository($this->app()))->sede()
        );
    }

    public function testBuscarUsuarioPorEmailSinDistinguirMayusculas(): void
    {
        $usuarios = new UsuarioRepository($this->app());

        $fila = $usuarios->buscarPorEmail('ANA@test.local');

        self::assertSame(['id' => 2, 'email' => 'ana@test.local', 'password_hash' => '-', 'rol' => 'TECNICO', 'tecnico_id' => 1, 'activo' => true], $fila);
        self::assertNull($usuarios->buscarPorEmail('nadie@test.local'));
    }

    public function testBuscarUsuarioActivoPorId(): void
    {
        $this->admin()->exec('UPDATE usuarios SET activo = FALSE WHERE id = 3');
        $usuarios = new UsuarioRepository($this->app());

        self::assertEquals(new Usuario(2, Usuario::TECNICO, 1), $usuarios->buscarActivoPorId(2));
        self::assertEquals(new Usuario(1, Usuario::PLANIFICADOR, null), $usuarios->buscarActivoPorId(1));
        self::assertNull($usuarios->buscarActivoPorId(3), 'Usuario inactivo');
        self::assertNull($usuarios->buscarActivoPorId(99));
    }
}
