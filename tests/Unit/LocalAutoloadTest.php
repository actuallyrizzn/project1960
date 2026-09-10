<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LocalAutoloadTest extends TestCase
{
    public function testPublicAutoloadRegistersProject1960Classes(): void
    {
        $path = dirname(__DIR__, 2) . '/public/includes/autoload.php';
        self::assertFileExists($path);

        $boot = file_get_contents(dirname(__DIR__, 2) . '/public/bootstrap.php');
        self::assertNotFalse($boot);
        self::assertStringContainsString('includes/autoload.php', $boot);
        self::assertStringContainsString('vendor/autoload.php', $boot);

        $index = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        self::assertNotFalse($index);
        self::assertStringContainsString('bootstrap.php', $index);
    }
}
