<?php
declare(strict_types=1);

use Project1960\View;

/** @var array<string, mixed>|null $adminUser */
/** @var array<string, mixed> $stats */
/** @var list<array{href: string, label: string, hint: string}> $links */
$adminUser = $adminUser ?? null;
$stats = $stats ?? [
    'total_cases' => 0,
    'mentions_1960' => 0,
    'verified_yes' => 0,
    'verified_no' => 0,
    'unprocessed_1960' => 0,
    'enrichment' => [],
];
$links = $links ?? [];
$verified = (int) ($stats['verified_yes'] ?? 0);
?>
<h1 class="h3 mb-3">Operator home</h1>
<?php if (is_array($adminUser)): ?>
    <p class="text-secondary">Signed in as <strong><?= View::e((string) ($adminUser['username'] ?? '')) ?></strong>
        (<?= View::e((string) ($adminUser['role'] ?? '')) ?>). Read-only summary — no burn actions here.</p>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="border border-secondary rounded p-3">
            <div class="text-secondary small">Total cases</div>
            <div class="fs-4"><?= View::e((string) ($stats['total_cases'] ?? 0)) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border border-secondary rounded p-3">
            <div class="text-secondary small">§1960 mentions</div>
            <div class="fs-4"><?= View::e((string) ($stats['mentions_1960'] ?? 0)) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border border-secondary rounded p-3">
            <div class="text-secondary small">Verified yes</div>
            <div class="fs-4"><?= View::e((string) $verified) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border border-secondary rounded p-3">
            <div class="text-secondary small">Unprocessed §1960</div>
            <div class="fs-4"><?= View::e((string) ($stats['unprocessed_1960'] ?? 0)) ?></div>
        </div>
    </div>
</div>

<h2 class="h5">Enrichment (verified cohort)</h2>
<p class="text-secondary small">Counts are verified-cohort numerators vs verified_yes = <?= View::e((string) $verified) ?>.</p>
<div class="table-responsive mb-4">
    <table class="table table-sm table-dark align-middle">
        <thead><tr><th>Table</th><th>Enriched</th><th>%</th></tr></thead>
        <tbody>
        <?php foreach (($stats['enrichment'] ?? []) as $table => $count): ?>
            <?php
            $count = (int) $count;
            $pct = $verified > 0 ? min(100, (int) round(100 * $count / $verified)) : 0;
            ?>
            <tr>
                <td><code><?= View::e((string) $table) ?></code></td>
                <td><?= View::e((string) $count) ?></td>
                <td><?= View::e((string) $pct) ?>%</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2 class="h5">Shortcuts</h2>
<div class="row g-3">
<?php foreach ($links as $link): ?>
    <div class="col-md-4">
        <a class="d-block border border-secondary rounded p-3 text-decoration-none link-light" href="<?= View::e($link['href']) ?>">
            <strong><?= View::e($link['label']) ?></strong>
            <div class="small text-secondary"><?= View::e($link['hint']) ?></div>
        </a>
    </div>
<?php endforeach; ?>
</div>
