<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/** Operator home dashboard — read-only control surface (AD-A7). */
final class AdminHomeController
{
    public function __construct(
        private PDO $pdo,
        private View $view = new View(),
    ) {
    }

    public function pageGet(): Response
    {
        $stats = Stats::collect($this->pdo);
        $links = [
            ['href' => '/admin/users', 'label' => 'Users', 'hint' => 'Operators and roles'],
            ['href' => '/admin/api-keys', 'label' => 'API Keys', 'hint' => 'Mint and revoke scoped keys'],
            ['href' => '/admin/pipeline', 'label' => 'Pipeline', 'hint' => 'Scrape / match / OCR status'],
            ['href' => '/admin/appearance', 'label' => 'Appearance', 'hint' => 'Skin Lab chrome'],
            ['href' => '/admin/help', 'label' => 'Help', 'hint' => 'Bootstrap, scopes, CLIs'],
            ['href' => '/enrichment', 'label' => 'Public enrichment', 'hint' => 'Verified-cohort progress'],
            ['href' => '/health', 'label' => 'Health', 'hint' => 'JSON ok probe'],
        ];

        return AdminShell::renderPage($this->view, 'admin/home', [
            'title' => 'Home',
            'currentPath' => '/admin',
            'adminUser' => (new AdminAuth($this->pdo))->user(),
            'stats' => $stats,
            'links' => $links,
        ], $this->pdo);
    }
}
