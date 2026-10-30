<?php

declare(strict_types=1);

namespace App;

use Closure;
use SensitiveParameter;

/**
 * Login, sesión y logout (premisa de seguridad 5).
 *
 * - Cookie de sesión con Secure, HttpOnly y SameSite=Strict en todos los entornos; solo lleva el id.
 * - session.use_strict_mode: un id de sesión inventado por el navegador no se acepta.
 * - Al entrar se regenera el id. Caduca a los 30 min sin actividad y a las 8 h como máximo.
 * - En cada petición el usuario se vuelve a leer de la base: si se desactiva, pierde el acceso al momento.
 * - Sin cookie no se abre sesión: las peticiones anónimas no crean sesiones.
 */
final class Auth
{
    public const COOKIE = 'planificador_sesion';
    public const INACTIVIDAD_MAXIMA = 30 * 60;
    public const DURACION_MAXIMA = 8 * 60 * 60;
    private const EMAIL_MAXIMO = 150;
    private const CLAVE_MAXIMA = 1024;

    // Hash de una cadena aleatoria descartada (nadie conoce la contraseña). Con un email que no
    // existe se verifica contra él, para que la respuesta tarde lo mismo y no revele qué emails hay.
    private const HASH_FICTICIO = '$2y$10$RX0I4PakDGhJ4vaVQkKReuDbUKcBJy.FsJMSpAdz/t4b9K.1zwgQK';

    private ?UsuarioRepository $repositorio = null;

    /** @param Closure(): UsuarioRepository $usuarios Se llama solo cuando hace falta la base de datos. */
    public function __construct(private readonly Closure $usuarios)
    {
    }

    /** Configuración de la sesión de PHP. Se llama una vez al arrancar, antes de cualquier salida. */
    public static function configurar(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.gc_maxlifetime', (string) self::DURACION_MAXIMA);
        session_name(self::COOKIE);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Comprueba email y contraseña y abre una sesión nueva con su token CSRF.
     * Ante cualquier fallo, el mismo 401 con el mismo mensaje.
     *
     * @return array{usuario: array<string, mixed>, csrf: string}
     */
    public function iniciarSesion(mixed $email, #[SensitiveParameter] mixed $clave): array
    {
        if (!is_string($email) || !is_string($clave) || $email === '' || $clave === ''
            || mb_strlen($email) > self::EMAIL_MAXIMO || strlen($clave) > self::CLAVE_MAXIMA) {
            throw ErroresDeNegocio::una(ErrorRegla::CAMPOS, 'Escribe tu email y tu contraseña');
        }

        $fila = $this->usuarios()->buscarPorEmail($email);
        $claveCorrecta = password_verify($clave, $fila['password_hash'] ?? self::HASH_FICTICIO);
        if (!$claveCorrecta || $fila === null || !$fila['activo']) {
            throw ErrorHttp::credencialesIncorrectas();
        }

        $perfil = $this->usuarios()->perfil($fila['id']) ?? throw ErrorHttp::credencialesIncorrectas();

        $this->abrir();
        session_regenerate_id(true);
        $ahora = time();
        $_SESSION = ['usuario_id' => $fila['id'], 'creada' => $ahora, 'ultima' => $ahora, 'csrf' => Csrf::generar()];

        return ['usuario' => $perfil, 'csrf' => $_SESSION['csrf']];
    }

    /** Usuario de la sesión, o null si no hay sesión válida (en ese caso la sesión se destruye). */
    public function usuarioActual(): ?Usuario
    {
        if (!isset($_COOKIE[self::COOKIE])) {
            return null;
        }
        $this->abrir();

        $id = $_SESSION['usuario_id'] ?? null;
        $creada = $_SESSION['creada'] ?? null;
        $ultima = $_SESSION['ultima'] ?? null;
        $ahora = time();
        if (!is_int($id) || !is_int($creada) || !is_int($ultima) || self::caducada($creada, $ultima, $ahora)) {
            $this->cerrarSesion();

            return null;
        }

        $usuario = $this->usuarios()->buscarActivoPorId($id);
        if ($usuario === null) {
            $this->cerrarSesion();

            return null;
        }
        $_SESSION['ultima'] = $ahora;

        return $usuario;
    }

    /** @return array{usuario: array<string, mixed>, csrf: string}|null */
    public function perfilActual(Usuario $usuario): ?array
    {
        $perfil = $this->usuarios()->perfil($usuario->id);
        $token = $this->tokenCsrf();

        return $perfil === null || $token === null ? null : ['usuario' => $perfil, 'csrf' => $token];
    }

    public function tokenCsrf(): ?string
    {
        $token = $_SESSION['csrf'] ?? null;

        return is_string($token) ? $token : null;
    }

    /** Vacía y destruye la sesión y ordena al navegador borrar la cookie. */
    public function cerrarSesion(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        setcookie(self::COOKIE, '', [
            'expires' => 1,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /** 30 min sin actividad o 8 h desde el inicio, lo que llegue antes. */
    public static function caducada(int $creada, int $ultima, int $ahora): bool
    {
        return $ahora - $ultima > self::INACTIVIDAD_MAXIMA || $ahora - $creada > self::DURACION_MAXIMA;
    }

    private function abrir(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    private function usuarios(): UsuarioRepository
    {
        return $this->repositorio ??= ($this->usuarios)();
    }
}
