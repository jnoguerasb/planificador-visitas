<?php

declare(strict_types=1);

namespace App;

use Closure;
use ErrorException;
use Throwable;

/**
 * Convierte cualquier excepción en una respuesta. Los errores de negocio y de la petición llevan
 * su mensaje; cualquier otro es un fallo interno: el detalle va al registro (error_log) y el
 * cliente solo recibe el mensaje genérico, sin rutas, SQL ni nombres de clases.
 */
final class ManejadorErrores
{
    public const MENSAJE_INTERNO = 'Ha ocurrido un error. Inténtalo de nuevo.';

    private readonly Closure $registrar;

    /** @param (callable(string): void)|null $registrar Por defecto, error_log. */
    public function __construct(?callable $registrar = null)
    {
        $this->registrar = $registrar === null
            ? static function (string $linea): void {
                error_log($linea);
            }
            : $registrar(...);
    }

    public function respuestaPara(Throwable $error): Respuesta
    {
        return match (true) {
            $error instanceof ErroresDeNegocio => Respuesta::errores(self::estadoDe($error), $error->errores),
            $error instanceof ErrorHttp => Respuesta::error($error->estado, $error->regla, $error->getMessage()),
            $error instanceof VisitaNoEncontrada => Respuesta::error(404, ErrorRegla::NO_ENCONTRADO, 'La visita no existe'),
            default => $this->errorInterno($error),
        };
    }

    /** PERMISO → 403, ESTADO → 409, el resto → 422. */
    public static function estadoDe(ErroresDeNegocio $error): int
    {
        $reglas = $error->reglas();

        return match (true) {
            in_array(ErrorRegla::PERMISO, $reglas, true) => 403,
            in_array(ErrorRegla::ESTADO, $reglas, true) => 409,
            default => 422,
        };
    }

    /**
     * Para el arranque de la aplicación: los avisos de PHP pasan a ser excepciones y cualquier
     * excepción o error fatal que se escape acaba en la respuesta genérica.
     */
    public function registrarGlobalmente(): void
    {
        set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
            if ((error_reporting() & $nivel) === 0) {
                return false;
            }
            throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
        });

        set_exception_handler(function (Throwable $error): void {
            $this->respuestaPara($error)->enviar();
        });

        register_shutdown_function(function (): void {
            $fallo = error_get_last();
            if ($fallo !== null && in_array($fallo['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                ($this->registrar)("Error fatal: {$fallo['message']} en {$fallo['file']}:{$fallo['line']}");
                Respuesta::error(500, ErrorRegla::INTERNO, self::MENSAJE_INTERNO)->enviar();
            }
        });
    }

    private function errorInterno(Throwable $error): Respuesta
    {
        ($this->registrar)(sprintf(
            "%s: %s en %s:%d\n%s",
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            $error->getTraceAsString()
        ));

        return Respuesta::error(500, ErrorRegla::INTERNO, self::MENSAJE_INTERNO);
    }
}
