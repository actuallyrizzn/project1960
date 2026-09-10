<?php
declare(strict_types=1);

use Project1960\Csrf;
use Project1960\View;

/** @var list<array<string, mixed>> $users */
/** @var string|null $flash */
/** @var string|null $error */
$users = $users ?? [];
$flash = $flash ?? null;
$error = $error ?? null;
?>
<h1 class="h3 mb-3">Users</h1>
<?php if (is_string($flash) && $flash !== ''): ?>
    <div class="alert alert-success"><?= View::e($flash) ?></div>
<?php endif; ?>
<?php if (is_string($error) && $error !== ''): ?>
    <div class="alert alert-danger"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="table-responsive">
            <table class="table table-dark table-striped align-middle">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Active</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= View::e((string) $u['id']) ?></td>
                        <td><?= View::e((string) $u['username']) ?></td>
                        <td><?= View::e((string) $u['email']) ?></td>
                        <td><?= View::e((string) $u['role']) ?></td>
                        <td><?= ((int) $u['is_active'] === 1) ? 'yes' : 'no' ?></td>
                        <td class="text-nowrap">
                            <form method="post" action="/admin/users" class="d-inline">
                                <?= Csrf::inputField() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= View::e((string) $u['id']) ?>">
                                <input type="hidden" name="is_active" value="<?= ((int) $u['is_active'] === 1) ? '0' : '1' ?>">
                                <button class="btn btn-sm btn-outline-warning" type="submit">
                                    <?= ((int) $u['is_active'] === 1) ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-5">
        <form method="post" action="/admin/users" class="card card-body bg-black border-secondary">
            <h2 class="h6">Create user</h2>
            <?= Csrf::inputField() ?>
            <input type="hidden" name="action" value="create">
            <div class="mb-2">
                <label class="form-label" for="username">Username</label>
                <input class="form-control" name="username" id="username" required>
            </div>
            <div class="mb-2">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" type="email" name="email" id="email" required>
            </div>
            <div class="mb-2">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" type="password" name="password" id="password" required minlength="12">
            </div>
            <div class="mb-3">
                <label class="form-label" for="role">Role</label>
                <select class="form-select" name="role" id="role">
                    <option value="operator">operator</option>
                    <option value="readonly">readonly</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit">Create</button>
        </form>
        <form method="post" action="/admin/users" class="card card-body bg-black border-secondary mt-3">
            <h2 class="h6">Reset password</h2>
            <?= Csrf::inputField() ?>
            <input type="hidden" name="action" value="reset_password">
            <div class="mb-2">
                <label class="form-label" for="reset_user_id">User ID</label>
                <input class="form-control" name="user_id" id="reset_user_id" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new_password">New password</label>
                <input class="form-control" type="password" name="password" id="new_password" required minlength="12">
            </div>
            <button class="btn btn-outline-light" type="submit">Reset</button>
        </form>
    </div>
</div>
