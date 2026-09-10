<?php
declare(strict_types=1);

use Project1960\Csrf;
use Project1960\View;

/** @var list<array{slug: string, label: string, theme: string}> $catalog */
/** @var string $currentSkin */
/** @var string $siteName */
/** @var string|null $flash */
/** @var string|null $error */
$catalog = $catalog ?? [];
$currentSkin = $currentSkin ?? 'hey';
$siteName = $siteName ?? 'Project 1960';
$flash = $flash ?? null;
$error = $error ?? null;
?>
<h1 class="h3 mb-3">Appearance</h1>
<p class="text-secondary">Skin Lab family chrome (Hey / Ledger / Brutalist / Obsidian), stored in <code>site_settings</code>.</p>
<?php if (is_string($flash) && $flash !== ''): ?>
    <div class="alert alert-success"><?= View::e($flash) ?></div>
<?php endif; ?>
<?php if (is_string($error) && $error !== ''): ?>
    <div class="alert alert-danger"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="post" action="/admin/appearance" class="card card-body bg-black border-secondary col-lg-7">
    <?= Csrf::inputField() ?>
    <input type="hidden" name="action" value="save">
    <div class="mb-3">
        <label class="form-label" for="site_name">Site name</label>
        <input class="form-control" name="site_name" id="site_name" value="<?= View::e($siteName) ?>" required maxlength="80">
    </div>
    <fieldset class="mb-3">
        <legend class="form-label">Admin skin</legend>
        <?php foreach ($catalog as $item): ?>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="skin" id="skin_<?= View::e($item['slug']) ?>"
                       value="<?= View::e($item['slug']) ?>"<?= $currentSkin === $item['slug'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="skin_<?= View::e($item['slug']) ?>">
                    <?= View::e($item['label']) ?>
                    <span class="text-secondary small">(<?= View::e($item['theme']) ?>)</span>
                </label>
            </div>
        <?php endforeach; ?>
    </fieldset>
    <button class="btn btn-primary" type="submit">Save appearance</button>
</form>
