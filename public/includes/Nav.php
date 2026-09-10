<?php
declare(strict_types=1);

namespace Project1960;

final class Nav
{
    /** @return list<array{href: string, label: string, icon: string}> */
    public static function items(): array
    {
        return [
            ['href' => '/', 'label' => 'Dashboard', 'icon' => 'bi-house'],
            ['href' => '/cases', 'label' => 'Cases', 'icon' => 'bi-list-ul'],
            ['href' => '/patterns', 'label' => 'Patterns', 'icon' => 'bi-diagram-3'],
            ['href' => '/enrichment', 'label' => 'Enrichment', 'icon' => 'bi-database'],
            ['href' => '/about', 'label' => 'About', 'icon' => ''],
        ];
    }

    public static function isActive(string $href, string $currentPath): bool
    {
        $current = $currentPath === '' ? '/' : $currentPath;
        if ($href === '/') {
            return $current === '/';
        }

        return $current === $href || str_starts_with($current, $href . '/');
    }
}
