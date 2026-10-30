<?php

declare(strict_types=1);

namespace App;

/** GET /api/tecnicos y GET /api/obras: datos para los desplegables del planificador. */
final class ControladorCatalogo
{
    public function __construct(private readonly CatalogoRepository $catalogo)
    {
    }

    public function tecnicos(Peticion $peticion): Respuesta
    {
        Entrada::parametros($peticion->query, []);

        $tecnicos = [];
        foreach ($this->catalogo->tecnicosActivos() as $id => $nombre) {
            $tecnicos[] = ['id' => $id, 'nombre' => $nombre];
        }

        return Respuesta::json(200, ['tecnicos' => $tecnicos]);
    }

    public function obras(Peticion $peticion): Respuesta
    {
        Entrada::parametros($peticion->query, []);

        return Respuesta::json(200, ['obras' => $this->catalogo->obras()]);
    }
}
