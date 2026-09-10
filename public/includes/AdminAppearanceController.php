<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/** Admin Appearance / Skin Lab UI (AD-A5). */
final class AdminAppearanceController
{
    public function __construct(
        private PDO $pdo,
        private View $view = new View(),
    ) {
    }

    public function pageGet(?string $flash = null, ?string $error = null): Response
    {
        $lab = new SkinLab($this->pdo);

        return AdminShell::renderPage($this->view, 'admin/appearance', [
            'title' => 'Appearance',
            'currentPath' => '/admin/appearance',
            'adminUser' => (new AdminAuth($this->pdo))->user(),
            'catalog' => SkinLab::catalog(),
            'currentSkin' => $lab->masterSlug(),
            'siteName' => $lab->siteName(),
            'flash' => $flash,
            'error' => $error,
        ], $this->pdo);
    }

    /** @param array<string, mixed> $post */
    public function pagePost(array $post): Response
    {
        if (!Csrf::verify(Csrf::tokenFromRequest($post, []))) {
            return $this->pageGet(null, 'Invalid security token.');
        }
        $lab = new SkinLab($this->pdo);
        try {
            $action = (string) ($post['action'] ?? '');
            if ($action === 'save') {
                $lab->setMasterSlug((string) ($post['skin'] ?? ''));
                $lab->setSiteName((string) ($post['site_name'] ?? ''));

                return $this->pageGet('Appearance saved.');
            }

            return $this->pageGet(null, 'Unknown action.');
        } catch (InvalidArgumentException $e) {
            return $this->pageGet(null, $e->getMessage());
        }
    }
}
