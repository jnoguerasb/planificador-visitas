<?php

declare(strict_types=1);

namespace App;

/** Petición HTTP recibida. Las cabeceras van con el nombre en minúsculas. */
final readonly class Peticion
{
    /**
     * @param array<array-key, mixed> $query Parámetros de la URL ($_GET).
     * @param array<string, string> $cabeceras
     * @param array<string, string> $parametros Partes variables de la ruta (por ejemplo, el id).
     */
    public function __construct(
        public string $metodo,
        public string $ruta,
        public array $query,
        public array $cabeceras,
        public string $cuerpo,
        public array $parametros = [],
        public ?Usuario $usuario = null,
    ) {
    }

    public static function desdeGlobales(): self
    {
        $cabeceras = [];
        foreach ($_SERVER as $clave => $valor) {
            if (is_string($valor) && str_starts_with((string) $clave, 'HTTP_')) {
                $cabeceras[strtolower(str_replace('_', '-', substr((string) $clave, 5)))] = $valor;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $cabeceras['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        // Se lee un byte más del máximo para poder detectar que el cuerpo es demasiado grande.
        $cuerpo = file_get_contents('php://input', false, null, 0, Entrada::TAMANO_MAXIMO + 1);

        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
            $_GET,
            $cabeceras,
            $cuerpo === false ? '' : $cuerpo,
        );
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    /**
     * El cuerpo como JSON, solo con los campos permitidos (ver Entrada::json).
     *
     * @param list<string> $permitidos
     * @return array<string, mixed>
     */
    public function json(array $permitidos): array
    {
        return Entrada::json($this->cabecera('content-type'), $this->cuerpo, $permitidos);
    }

    /** @param array<string, string> $parametros */
    public function con(array $parametros, ?Usuario $usuario): self
    {
        return new self($this->metodo, $this->ruta, $this->query, $this->cabeceras, $this->cuerpo, $parametros, $usuario);
    }
}
