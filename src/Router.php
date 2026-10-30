<?php

declare(strict_types=1);

namespace App;

/**
 * Busca la ruta en la tabla y aplica, por este orden, las comprobaciones comunes:
 *
 * 1. Lo que no está en la tabla da 404 (también un método distinto). No se traducen rutas a ficheros.
 * 2. En los POST, una cabecera Origin de otro sitio da 403.
 * 3. En las rutas privadas: sin sesión, 401; con un rol no permitido, 403; en los POST, sin token CSRF válido, 403.
 */
final class Router
{
    /** @param list<Ruta> $rutas */
    public function __construct(
        private readonly array $rutas,
        private readonly Auth $auth,
    ) {
    }

    public function atender(Peticion $peticion): Respuesta
    {
        [$ruta, $parametros] = $this->buscar($peticion);

        if ($peticion->metodo === 'POST' && !self::origenPermitido($peticion->cabecera('origin'), $peticion->cabecera('host') ?? '')) {
            throw ErrorHttp::prohibido('Petición no permitida desde otro sitio');
        }

        $usuario = null;
        if (!$ruta->esPublica()) {
            $usuario = $this->auth->usuarioActual() ?? throw ErrorHttp::noAutenticado();
            if (!in_array($usuario->rol, $ruta->roles ?? [], true)) {
                throw ErrorHttp::prohibido();
            }
        }
        if ($peticion->metodo === 'POST' && $ruta->csrf && !Csrf::esValido($this->auth->tokenCsrf(), $peticion->cabecera(Csrf::CABECERA))) {
            throw ErrorHttp::prohibido('La sesión no es válida. Recarga la página.');
        }

        return ($ruta->accion)($peticion->con($parametros, $usuario));
    }

    /**
     * Sin cabecera Origin (peticiones del mismo sitio en algunos navegadores, curl) se permite.
     * Si la hay, su host y puerto deben coincidir con el host al que va dirigida la petición.
     */
    public static function origenPermitido(?string $origen, string $host): bool
    {
        if ($origen === null) {
            return true;
        }
        $partes = parse_url($origen);
        if ($host === '' || !is_array($partes) || !isset($partes['host'])) {
            return false;
        }
        $origenHost = $partes['host'] . (isset($partes['port']) ? ':' . $partes['port'] : '');

        return strtolower($origenHost) === strtolower($host);
    }

    /** @return array{Ruta, array<string, string>} */
    private function buscar(Peticion $peticion): array
    {
        foreach ($this->rutas as $ruta) {
            if ($ruta->metodo === $peticion->metodo && preg_match($ruta->patron, $peticion->ruta, $coincidencias) === 1) {
                return [$ruta, array_filter($coincidencias, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }

        throw ErrorHttp::noEncontrado();
    }
}
