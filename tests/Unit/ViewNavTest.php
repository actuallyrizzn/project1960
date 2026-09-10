<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\Nav;
use Project1960\View;
use RuntimeException;

final class ViewNavTest extends TestCase
{
    public function testEscapeHtml(): void
    {
        self::assertSame('&lt;b&gt;', View::e('<b>'));
    }

    public function testRenderPageInsideLayout(): void
    {
        $view = new View();
        $html = $view->renderInLayout('pages/home', [
            'title' => 'Dashboard — Project 1960',
            'currentPath' => '/',
        ]);

        self::assertStringContainsString('Project 1960', $html);
        self::assertStringContainsString('Dashboard', $html);
        self::assertStringContainsString('themeToggle', $html);
        self::assertStringContainsString('/assets/js/theme.js', $html);
        self::assertStringContainsString('nav-link active', $html);
    }

    public function testMissingViewThrows(): void
    {
        $view = new View();
        $this->expectException(RuntimeException::class);
        $view->render('pages/does-not-exist');
    }

    public function testNavActiveRules(): void
    {
        self::assertTrue(Nav::isActive('/', '/'));
        self::assertFalse(Nav::isActive('/', '/cases'));
        self::assertTrue(Nav::isActive('/cases', '/cases'));
        self::assertTrue(Nav::isActive('/cases', '/cases/abc'));
        self::assertCount(5, Nav::items());
    }

    public function testShellRoutesRenderNav(): void
    {
        $app = new \Project1960\App();
        foreach (['/cases', '/patterns', '/enrichment', '/about'] as $path) {
            $response = $app->handle(new \Project1960\Request('GET', $path));
            self::assertSame(200, $response->status);
            self::assertStringContainsString('navbar', $response->body);
            self::assertStringContainsString('themeToggle', $response->body);
        }
    }
}
