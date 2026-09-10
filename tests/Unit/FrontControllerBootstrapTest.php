<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Multihost try_files front controllers must climb to public/bootstrap.php.
 */
final class FrontControllerBootstrapTest extends TestCase
{
    public function testNestedFrontControllersUseCorrectDirnameLevels(): void
    {
        $public = dirname(__DIR__, 2) . '/public';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public));
        $files = new RegexIterator($it, '/index\.php$/');
        $checked = 0;
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $rel = substr($path, strlen($public) + 1);
            if ($rel === 'index.php') {
                continue;
            }
            $text = (string) file_get_contents($path);
            if (!str_contains($text, 'bootstrap.php')) {
                continue;
            }
            $depth = substr_count($rel, '/'); // dirs under public (index.php excluded)
            if (!preg_match('/dirname\(__DIR__(?:,\s*(\d+))?\)/', $text, $m)) {
                self::fail("No dirname(__DIR__) bootstrap require in {$rel}");
            }
            $got = isset($m[1]) ? (int) $m[1] : 1;
            self::assertSame(
                $depth,
                $got,
                "{$rel}: expected dirname(__DIR__, {$depth}), got {$got}"
            );
            $checked++;
        }
        self::assertGreaterThan(10, $checked);
    }
}
