<?php
declare(strict_types=1);

use Project1960\View;

/** @var array<string, mixed>|null $adminUser */
?>
<h1 class="h3 mb-3">Operator home</h1>
<p class="text-secondary">Control dashboard shell (AD-A1). Stats and pipeline panels land in later slices.</p>
<?php if (is_array($adminUser)): ?>
    <p>Signed in as <strong><?= View::e((string) ($adminUser['username'] ?? '')) ?></strong>
        (<?= View::e((string) ($adminUser['role'] ?? '')) ?>).</p>
<?php endif; ?>
