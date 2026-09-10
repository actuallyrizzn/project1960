<?php
declare(strict_types=1);

use Project1960\Nav;
use Project1960\View;

/** @var string $content */
/** @var string $title */
/** @var string $currentPath */
$title = $title ?? 'Project 1960';
$currentPath = $currentPath ?? '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::e($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="/">
                <i class="bi bi-shield-check me-2"></i>
                Project 1960
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php foreach (Nav::items() as $item): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= Nav::isActive($item['href'], $currentPath) ? ' active' : '' ?>" href="<?= View::e($item['href']) ?>">
                                <?php if ($item['icon'] !== ''): ?>
                                    <i class="bi <?= View::e($item['icon']) ?> me-1"></i>
                                <?php endif; ?>
                                <?= View::e($item['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li class="nav-item">
                        <button class="theme-toggle" id="themeToggle" type="button" title="Toggle Dark Mode" aria-label="Toggle dark mode">
                            <i class="bi bi-moon-fill" id="themeIcon"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="container">
        <?= $content ?>
    </main>

    <footer class="mt-5 py-4 text-center text-muted">
        <div class="container">
            <p class="mb-0">
                <i class="bi bi-code-slash me-1"></i>
                Project 1960 — project1960.rizzn.net
            </p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/theme.js"></script>
</body>
</html>
