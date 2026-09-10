<?php
declare(strict_types=1);

use Project1960\View;

/** @var array<string, array<string, int|string|null>> $status */
$status = $status ?? ['scrape' => [], 'match' => [], 'documents' => [], 'extract' => []];
?>
<h1 class="h3 mb-3">Pipeline</h1>
<p class="text-secondary">Read-only status from the live SQLite stores. Controls that enqueue work land in AD-P2 (no burn here).</p>

<div class="row g-4">
<?php foreach (['scrape' => 'Scrape', 'match' => 'CL match', 'documents' => 'Documents / OCR', 'extract' => 'Extract'] as $key => $label): ?>
    <div class="col-md-6">
        <div class="border border-secondary rounded p-3 h-100">
            <h2 class="h5"><?= View::e($label) ?></h2>
            <table class="table table-sm table-dark mb-0">
                <tbody>
                <?php foreach (($status[$key] ?? []) as $metric => $value): ?>
                    <tr>
                        <td><code><?= View::e((string) $metric) ?></code></td>
                        <td class="text-end"><?= View::e((string) $value) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
</div>
