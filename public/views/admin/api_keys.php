<?php
declare(strict_types=1);

use Project1960\Csrf;
use Project1960\View;

/** @var list<array<string, mixed>> $keys */
/** @var list<string> $allScopes */
/** @var string|null $plaintextOnce */
/** @var string|null $flash */
/** @var string|null $error */
$keys = $keys ?? [];
$allScopes = $allScopes ?? [];
$plaintextOnce = $plaintextOnce ?? null;
$flash = $flash ?? null;
$error = $error ?? null;
?>
<h1 class="h3 mb-3">API Keys</h1>
<?php if (is_string($flash) && $flash !== ''): ?>
    <div class="alert alert-success"><?= View::e($flash) ?></div>
<?php endif; ?>
<?php if (is_string($error) && $error !== ''): ?>
    <div class="alert alert-danger"><?= View::e($error) ?></div>
<?php endif; ?>
<?php if (is_string($plaintextOnce) && $plaintextOnce !== ''): ?>
    <div class="alert alert-warning">
        <strong>Plaintext (once):</strong>
        <code class="user-select-all"><?= View::e($plaintextOnce) ?></code>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="table-responsive">
            <table class="table table-dark table-striped align-middle">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Preview</th>
                    <th>User</th>
                    <th>Scopes</th>
                    <th>Revoked</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($keys as $k): ?>
                    <tr>
                        <td><?= View::e((string) $k['id']) ?></td>
                        <td><?= View::e((string) $k['key_name']) ?></td>
                        <td><code><?= View::e((string) $k['key_preview']) ?></code></td>
                        <td><?= View::e((string) ($k['username'] ?? $k['user_id'])) ?></td>
                        <td><small><?= View::e(implode(', ', $k['scopes'] ?? [])) ?></small></td>
                        <td><?= !empty($k['revoked_at']) ? View::e((string) $k['revoked_at']) : '—' ?></td>
                        <td>
                            <?php if (empty($k['revoked_at'])): ?>
                                <form method="post" action="/admin/api-keys" class="d-inline">
                                    <?= Csrf::inputField() ?>
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="key_id" value="<?= View::e((string) $k['id']) ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-5">
        <form method="post" action="/admin/api-keys" class="card card-body bg-black border-secondary">
            <h2 class="h6">Mint key</h2>
            <?= Csrf::inputField() ?>
            <input type="hidden" name="action" value="mint">
            <div class="mb-2">
                <label class="form-label" for="key_name">Name</label>
                <input class="form-control" name="key_name" id="key_name" value="admin" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Scopes</label>
                <?php foreach ($allScopes as $scope): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="scopes[]" value="<?= View::e($scope) ?>" id="sc_<?= View::e(str_replace(':', '_', $scope)) ?>" checked>
                        <label class="form-check-label" for="sc_<?= View::e(str_replace(':', '_', $scope)) ?>"><?= View::e($scope) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-primary" type="submit">Mint</button>
        </form>
    </div>
</div>
