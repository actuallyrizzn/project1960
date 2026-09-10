<?php
declare(strict_types=1);

use Project1960\View;

/** @var list<array{slug: string, title: string, summary: string}> $topics */
$topics = $topics ?? [];
?>
<h1 class="h3 mb-3">Help</h1>
<p class="text-secondary">Operator docs for bootstrap, API scopes, enrichment math, and pipeline CLIs.</p>
<ul class="list-group list-group-flush col-lg-8">
<?php foreach ($topics as $t): ?>
    <li class="list-group-item bg-transparent text-light border-secondary">
        <a class="link-light" href="/admin/help/<?= View::e($t['slug']) ?>"><?= View::e($t['title']) ?></a>
        <div class="small text-secondary"><?= View::e($t['summary']) ?></div>
    </li>
<?php endforeach; ?>
</ul>
