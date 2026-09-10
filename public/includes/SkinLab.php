<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/**
 * Skin Lab family chrome for admin (Tasks/Environment parity, AD-A5).
 */
final class SkinLab
{
    public const KEY_MASTER = 'appearance.skin';
    public const KEY_SITE_NAME = 'appearance.site_name';

    /** @var list<string> */
    public const SLUGS = ['hey', 'ledger', 'brutalist', 'obsidian'];

    public static function normalize(?string $slug): ?string
    {
        $s = strtolower(trim((string) $slug));

        return in_array($s, self::SLUGS, true) ? $s : null;
    }

    public static function bootstrapTheme(string $slug): string
    {
        return $slug === 'obsidian' ? 'dark' : 'light';
    }

    /** @return list<array{slug: string, label: string, theme: string}> */
    public static function catalog(): array
    {
        return [
            ['slug' => 'hey', 'label' => 'Hey', 'theme' => 'light'],
            ['slug' => 'ledger', 'label' => 'Ledger', 'theme' => 'light'],
            ['slug' => 'brutalist', 'label' => 'Brutalist', 'theme' => 'light'],
            ['slug' => 'obsidian', 'label' => 'Obsidian', 'theme' => 'dark'],
        ];
    }

    public function __construct(private PDO $pdo)
    {
    }

    public function masterSlug(): string
    {
        $raw = (new SiteSettings($this->pdo))->get(self::KEY_MASTER, 'hey');

        return self::normalize($raw) ?? 'hey';
    }

    public function effectiveSlug(?string $preview = null): string
    {
        $p = self::normalize($preview);
        if ($p !== null) {
            return $p;
        }

        return $this->masterSlug();
    }

    public function setMasterSlug(string $slug): string
    {
        $n = self::normalize($slug);
        if ($n === null) {
            throw new InvalidArgumentException('Unknown skin slug');
        }
        (new SiteSettings($this->pdo))->set(self::KEY_MASTER, $n);

        return $n;
    }

    public function siteName(): string
    {
        $name = trim((string) (new SiteSettings($this->pdo))->get(self::KEY_SITE_NAME, 'Project 1960'));

        return $name !== '' ? $name : 'Project 1960';
    }

    public function setSiteName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Site name cannot be empty');
        }
        if (strlen($name) > 80) {
            $name = substr($name, 0, 80);
        }
        (new SiteSettings($this->pdo))->set(self::KEY_SITE_NAME, $name);

        return $name;
    }
}
