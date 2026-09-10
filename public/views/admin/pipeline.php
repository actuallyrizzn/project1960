<?php
declare(strict_types=1);

use Project1960\Csrf;
use Project1960\View;

/** @var array<string, array<string, int|string|null>> $status */
/** @var list<string> $actions */
/** @var bool $burnAllowed */
/** @var array<string, mixed>|null $lastDryRun */
/** @var array<string, mixed>|null $lastEnqueue */
/** @var array<string, mixed>|null $result */
/** @var string|null $flash */
/** @var string|null $error */
$status = $status ?? ['scrape' => [], 'match' => [], 'documents' => [], 'extract' => []];
$actions = $actions ?? [];
$burnAllowed = $burnAllowed ?? false;
$lastDryRun = $lastDryRun ?? null;
$lastEnqueue = $lastEnqueue ?? null;
$result = $result ?? null;
$flash = $flash ?? null;
$error = $error ?? null;
?>
<h1 class="h3 mb-3">Pipeline</h1>
<p class="text-secondary">Status is read-only from SQLite. Controls below are dry-run / enqueue-intent only — unlimited enrich burn is blocked from the UI.</p>
<?php if (is_string($flash) && $flash !== ''): ?>
    <div class="alert alert-success"><?= View::e($flash) ?></div>
<?php endif; ?>
<?php if (is_string($error) && $error !== ''): ?>
    <div class="alert alert-danger"><?= View::e($error) ?></div>
<?php endif; ?>
<?php if (is_array($result)): ?>
    <div class="alert alert-info"><code><?= View::e(json_encode($result, JSON_UNESCAPED_SLASHES) ?: '') ?></code></div>
<?php endif; ?>

<div class="row g-4 mb-4">
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

<div class="row g-4">
    <div class="col-lg-6">
        <form method="post" action="/admin/pipeline/" class="card card-body bg-black border-secondary">
            <h2 class="h6">Dry-run</h2>
            <?= Csrf::inputField() ?>
            <input type="hidden" name="mode" value="dry_run">
            <div class="mb-2">
                <label class="form-label" for="dry_action">Action</label>
                <select class="form-select" name="action" id="dry_action">
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= View::e($a) ?>"><?= View::e($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="dry_limit">Limit</label>
                <input class="form-control" type="number" name="limit" id="dry_limit" value="10" min="1" max="100">
            </div>
            <button class="btn btn-outline-light" type="submit">Record dry-run</button>
        </form>
        <?php if (is_array($lastDryRun)): ?>
            <p class="small text-secondary mt-2">Last dry-run: <code><?= View::e((string) ($lastDryRun['message'] ?? '')) ?></code></p>
        <?php endif; ?>
    </div>
    <div class="col-lg-6">
        <form method="post" action="/admin/pipeline/" class="card card-body bg-black border-secondary">
            <h2 class="h6">Enqueue (dry intent)</h2>
            <?= Csrf::inputField() ?>
            <input type="hidden" name="mode" value="enqueue">
            <div class="mb-2">
                <label class="form-label" for="eq_action">Action</label>
                <select class="form-select" name="action" id="eq_action">
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= View::e($a) ?>"><?= View::e($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label" for="eq_limit">Limit</label>
                <input class="form-control" type="number" name="limit" id="eq_limit" value="10" min="1" max="50">
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="confirm_burn" value="1" id="confirm_burn">
                <label class="form-check-label" for="confirm_burn">Attempt live burn (blocked unless env + Mark go)</label>
            </div>
            <p class="small text-secondary">Burn gate env: <code>P1960_PIPELINE_ALLOW_BURN</code> currently <?= $burnAllowed ? 'on' : 'off' ?>.</p>
            <button class="btn btn-primary" type="submit">Enqueue dry intent</button>
        </form>
        <?php if (is_array($lastEnqueue)): ?>
            <p class="small text-secondary mt-2">Last enqueue: <code><?= View::e((string) ($lastEnqueue['message'] ?? '')) ?></code></p>
        <?php endif; ?>
    </div>
</div>
