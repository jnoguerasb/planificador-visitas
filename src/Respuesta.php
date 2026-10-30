<?php

declare(strict_types=1);

namespace App;

/** Respuesta HTTP. Todas llevan las cabeceras de seguridad, también las de error. */
final readonly class Respuesta
{
    private const CABECERAS_DE_SEGURIDAD = [
        'Content-Security-Policy' => "default-src 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'X-Frame-Options' => 'DENY',
    ];

    /** @param array<string, string> $cabeceras */
    public function __construct(
        public int $estado,
        public string $cuerpo = '',
        public array $cabeceras = [],
    ) {
    }

    /** @param array<string, mixed> $datos */
    public static function json(int $estado, array $datos): self
    {
        return new self(
            $estado,
            json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    /**
     * Formato común de error: {"errores": [{"regla", "mensaje", "campo"?}]}.
     *
     * @param list<ErrorRegla> $errores
     */
    public static function errores(int $estado, array $errores): self
    {
        return self::json($estado, ['errores' => array_map(
            fn (ErrorRegla $e) => ['regla' => $e->regla, 'mensaje' => $e->mensaje] + ($e->campo === null ? [] : ['campo' => $e->campo]),
            $errores
        )]);
    }

    public static function error(int $estado, string $regla, string $mensaje): self
    {
        return self::errores($estado, [new ErrorRegla($regla, $mensaje)]);
    }

    public static function sinContenido(): self
    {
        return new self(204, '', ['Cache-Control' => 'no-store']);
    }

    public static function html(string $html): self
    {
        return new self(200, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @return array<string, string> */
    public function cabecerasCompletas(): array
    {
        return self::CABECERAS_DE_SEGURIDAD + $this->cabeceras;
    }

    public function enviar(): void
    {
        if (!headers_sent()) {
            http_response_code($this->estado);
            header_remove('X-Powered-By');
            foreach ($this->cabecerasCompletas() as $nombre => $valor) {
                header("{$nombre}: {$valor}");
            }
        }
        echo $this->cuerpo;
    }
}
