<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comprobaciones de los archivos de despliegue que se pueden hacer sin construir la imagen
 * (el equipo de desarrollo no tiene Docker; el build real lo hace Render).
 */
final class DespliegueTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    public function testDockerfileDeProduccion(): void
    {
        $dockerfile = $this->leer('Dockerfile');

        self::assertMatchesRegularExpression('/^FROM php:8\.3-apache$/m', $dockerfile);
        self::assertStringContainsString('docker-php-ext-install pdo_pgsql', $dockerfile);
        self::assertStringContainsString('php.ini-production', $dockerfile);
        self::assertMatchesRegularExpression('/composer install --no-dev/', $dockerfile);
        self::assertMatchesRegularExpression('/dump-autoload --no-dev/', $dockerfile);
        // Solo las instrucciones: los comentarios pueden nombrar lo que no se instala.
        $instrucciones = (string) preg_replace('/^\s*#.*$/m', '', $dockerfile);
        foreach (['pcov', 'xdebug', 'phpunit', 'COPY tests', 'COPY .env', 'COPY . '] as $prohibido) {
            self::assertStringNotContainsStringIgnoringCase($prohibido, $instrucciones, "El Dockerfile no debe incluir {$prohibido}");
        }
    }

    public function testApacheSirveSoloPublicYPasaPorElRouter(): void
    {
        $apache = $this->leer('docker/apache.conf');

        self::assertStringContainsString('DocumentRoot /var/www/planificador/public', $apache);
        self::assertStringContainsString('Options -Indexes', $apache);
        self::assertStringContainsString('RewriteRule ^ index.php [L]', $apache);
        foreach (["Content-Security-Policy \"default-src 'self'\"", 'X-Content-Type-Options "nosniff"', 'Referrer-Policy "same-origin"', 'X-Frame-Options "DENY"'] as $cabecera) {
            self::assertStringContainsString("Header always set {$cabecera}", $apache);
        }
        self::assertStringContainsString('Listen ${PORT}', $this->leer('docker/ports.conf'));
    }

    public function testPhpDeProduccionNoMuestraErrores(): void
    {
        $ini = $this->leer('docker/php.ini');

        self::assertMatchesRegularExpression('/^display_errors = Off$/m', $ini);
        self::assertMatchesRegularExpression('/^expose_php = Off$/m', $ini);
        self::assertMatchesRegularExpression('/^zend\.exception_ignore_args = On$/m', $ini);
        self::assertMatchesRegularExpression('/^date\.timezone = Europe\/Madrid$/m', $ini);
    }

    public function testRenderYamlSinSecretos(): void
    {
        $yaml = $this->leer('render.yaml');

        self::assertMatchesRegularExpression('/runtime: docker/', $yaml);
        self::assertMatchesRegularExpression('/- key: APP_ENV\s+value: production/', $yaml);
        foreach (['DATABASE_URL', 'DEMO_PASSWORD'] as $secreto) {
            self::assertMatchesRegularExpression("/- key: {$secreto}\\s+sync: false/", $yaml, "{$secreto} debe pedirse en el panel (sync: false)");
        }
        self::assertStringNotContainsString('postgres://', $yaml);
    }

    public function testDockerignoreDejaFueraSecretosYDesarrollo(): void
    {
        $lineas = array_map('trim', explode("\n", $this->leer('.dockerignore')));

        foreach (['.env', '.git', 'vendor', 'tests'] as $excluido) {
            self::assertContains($excluido, $lineas);
        }
    }

    /** @return array<string, array{string}> */
    public static function archivosDelContenedor(): array
    {
        return [
            'script de arranque' => ['docker/arrancar.sh'],
            'Apache' => ['docker/apache.conf'],
            'puertos' => ['docker/ports.conf'],
            'PHP' => ['docker/php.ini'],
            'Dockerfile' => ['Dockerfile'],
        ];
    }

    /** En Linux un script con CRLF no arranca ("/bin/sh^M: not found"). */
    #[DataProvider('archivosDelContenedor')]
    public function testFinalesDeLineaLf(string $archivo): void
    {
        self::assertStringNotContainsString("\r", $this->leer($archivo));
    }

    public function testElScriptDeArranqueCreaLosUsuariosYArrancaApache(): void
    {
        $script = $this->leer('docker/arrancar.sh');

        self::assertStringStartsWith("#!/bin/sh\n", $script);
        self::assertStringContainsString('export PORT="${PORT:-10000}"', $script);
        self::assertStringContainsString('bin/crear_usuarios_demo.php', $script);
        self::assertMatchesRegularExpression('/^exec apache2-foreground$/m', $script);
    }

    private function leer(string $ruta): string
    {
        $contenido = file_get_contents(self::RAIZ . '/' . $ruta);
        self::assertIsString($contenido, "No se puede leer {$ruta}");

        return $contenido;
    }
}
