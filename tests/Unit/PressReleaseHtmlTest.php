<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\PressReleaseHtml;

final class PressReleaseHtmlTest extends TestCase
{
    public function testRendersSafeParagraphsNotEscapedTags(): void
    {
        $html = PressReleaseHtml::render('<p>KANSAS CITY — charged under <strong>18 U.S.C. 1960</strong>.</p>');
        self::assertStringContainsString('<p>', $html);
        self::assertStringContainsString('<strong>', $html);
        self::assertStringNotContainsString('&lt;p&gt;', $html);
        self::assertStringContainsString('KANSAS CITY', $html);
    }

    public function testStripsScriptsAndEventHandlers(): void
    {
        $html = PressReleaseHtml::render(
            '<p onclick="alert(1)">Safe</p><script>evil()</script><a href="javascript:alert(1)">x</a>'
        );
        self::assertStringNotContainsString('<script', strtolower($html));
        self::assertStringNotContainsString('onclick', strtolower($html));
        self::assertStringNotContainsString('javascript:', strtolower($html));
        self::assertStringContainsString('Safe', $html);
    }

    public function testPlainTextGetsEscapedAndBreaks(): void
    {
        $html = PressReleaseHtml::render("Line one.\n\nLine <two>.");
        self::assertStringContainsString('Line one.', $html);
        self::assertStringContainsString('<br', $html);
        self::assertStringContainsString('&lt;two&gt;', $html);
    }

    public function testEmpty(): void
    {
        self::assertSame('', PressReleaseHtml::render(null));
        self::assertSame('', PressReleaseHtml::render('   '));
    }

    public function testAllowsHttpsLinksWithRel(): void
    {
        $html = PressReleaseHtml::render('<p><a href="https://www.justice.gov/x" target="_blank">DOJ</a></p>');
        self::assertStringContainsString('href="https://www.justice.gov/x"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
    }
}
