<?php
declare(strict_types=1);

use Project1960\AdminShell;
use Project1960\View;

/** @var string $content */
/** @var string $title */
/** @var string $currentPath */
/** @var list<array{key: string, label: string, href: string, icon: string}> $adminNav */
/** @var array<string, mixed>|null $adminUser */
/** @var string $skinSlug */
/** @var string $bsTheme */
/** @var string $siteBrand */
$title = $title ?? 'Admin';
$currentPath = $currentPath ?? '/admin';
$adminNav = $adminNav ?? AdminShell::navItems();
$adminUser = $adminUser ?? null;
$skinSlug = $skinSlug ?? 'hey';
$bsTheme = $bsTheme ?? 'dark';
$siteBrand = $siteBrand ?? 'Project 1960';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= View::e($bsTheme) ?>" data-skin="<?= View::e($skinSlug) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::e($title) ?> · <?= View::e($siteBrand) ?> Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
    <link href="/assets/css/skins.css" rel="stylesheet">
</head>
<body class="admin-body skin-<?= View::e($skinSlug) ?>">
<nav class="navbar navbar-expand-lg border-bottom mb-4 admin-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="/admin"><?= View::e($siteBrand) ?> Admin</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav" aria-controls="adminNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNav">
            <ul class="navbar-nav me-auto">
                <?php foreach ($adminNav as $item): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= AdminShell::isActive($item['href'], $currentPath) ? ' active' : '' ?>"
                           href="<?= View::e($item['href']) ?>">
                            <i class="bi <?= View::e($item['icon']) ?> me-1"></i><?= View::e($item['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <ul class="navbar-nav">
                <?php if (is_array($adminUser)): ?>
                    <li class="nav-item">
                        <span class="navbar-text me-3"><?= View::e((string) ($adminUser['username'] ?? '')) ?></span>
                    </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="/admin/logout">Logout</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/">Public site</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<main class="container-fluid px-4 pb-5">
    <?= $content ?? '' ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
