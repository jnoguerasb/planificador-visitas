<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

final class EntornoTest extends TestCase
{
    public function testLaVersionDePhpEs83(): void
    {
        self::assertSame('8.3', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
    }

    public function testLaExtensionPdoPgsqlEstaCargada(): void
    {
        self::assertTrue(extension_loaded('pdo_pgsql'));
        self::assertContains('pgsql', PDO::getAvailableDrivers());
    }
}
