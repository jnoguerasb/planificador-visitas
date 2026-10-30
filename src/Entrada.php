<?php

declare(strict_types=1);

namespace App;

use JsonException;
use stdClass;

/**
 * Lectura estricta de lo que envía el cliente. Lo que no se puede leer da 400; lo que se lee
 * pero no es válido da 422 (CAMPOS). Nunca se corrige ni se completa nada.
 */
final class Entrada
{
    public const TAMANO_MAXIMO = 16_384;
    private const PROFUNDIDAD_MAXIMA = 8;

    /**
     * Cuerpo JSON: debe ser un objeto y solo con campos de $permitidos (no hace falta que estén todos).
     *
     * @param list<string> $permitidos
     * @return array<string, mixed>
     */
    public static function json(?string $tipo, string $cuerpo, array $permitidos): array
    {
        if ($tipo === null || preg_match('#^application/json\s*(;\s*charset=utf-8\s*)?$#iD', $tipo) !== 1) {
            throw ErrorHttp::peticionNoValida('La petición debe enviarse en formato JSON');
        }
        if (strlen($cuerpo) > self::TAMANO_MAXIMO) {
            throw ErrorHttp::peticionNoValida('La petición es demasiado grande');
        }

        try {
            $objeto = json_decode($cuerpo, false, self::PROFUNDIDAD_MAXIMA, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw ErrorHttp::peticionNoValida('La petición no es un JSON válido');
        }
        if (!$objeto instanceof stdClass) {
            throw ErrorHttp::peticionNoValida('La petición debe ser un objeto JSON');
        }

        $datos = get_object_vars($objeto);
        self::rechazarNoPermitidos(array_keys($datos), $permitidos);

        return $datos;
    }

    /**
     * Parámetros de la URL (GET). Solo los de $permitidos y solo como texto; vacío cuenta como ausente.
     *
     * @param array<array-key, mixed> $query Normalmente $_GET.
     * @param list<string> $permitidos
     * @return array<string, string>
     */
    public static function parametros(array $query, array $permitidos): array
    {
        self::rechazarNoPermitidos(array_keys($query), $permitidos);

        $parametros = [];
        $errores = [];
        foreach ($query as $nombre => $valor) {
            if (!is_string($valor)) {
                $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, "El parámetro {$nombre} no es válido", (string) $nombre);
            } elseif ($valor !== '') {
                $parametros[(string) $nombre] = $valor;
            }
        }
        if ($errores !== []) {
            throw new ErroresDeNegocio($errores);
        }

        return $parametros;
    }

    /** Entero positivo escrito sin signo, sin ceros a la izquierda ni decimales. null si no se ha enviado. */
    public static function entero(?string $valor, string $campo, string $mensaje): ?int
    {
        if ($valor === null) {
            return null;
        }
        $entero = preg_match('/^[1-9][0-9]*$/D', $valor) === 1 ? filter_var($valor, FILTER_VALIDATE_INT) : false;
        if ($entero === false) {
            throw ErroresDeNegocio::una(ErrorRegla::CAMPOS, $mensaje, $campo);
        }

        return $entero;
    }

    /**
     * @param list<int|string> $recibidos
     * @param list<string> $permitidos
     */
    private static function rechazarNoPermitidos(array $recibidos, array $permitidos): void
    {
        $errores = [];
        foreach ($recibidos as $nombre) {
            if (!in_array((string) $nombre, $permitidos, true)) {
                $errores[] = new ErrorRegla(ErrorRegla::CAMPOS, "Campo no permitido: {$nombre}", (string) $nombre);
            }
        }
        if ($errores !== []) {
            throw new ErroresDeNegocio($errores);
        }
    }
}
