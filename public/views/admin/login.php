<?php
declare(strict_types=1);

use Project1960\Csrf;
use Project1960\View;

/** @var string|null $error */
$error = $error ?? null;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login · Project 1960 Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-dark text-light d-flex align-items-center min-vh-100">
<div class="container" style="max-width: 420px;">
    <h1 class="h4 mb-3">Project 1960 Admin</h1>
    <?php if (is_string($error) && $error !== ''): ?>
        <div class="alert alert-danger"><?= View::e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/admin/login" class="card card-body bg-black border-secondary">
        <?= Csrf::inputField() ?>
        <div class="mb-3">
            <label class="form-label" for="username">Username or email</label>
            <input class="form-control" type="text" name="username" id="username" required autocomplete="username">
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input class="form-control" type="password" name="password" id="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary w-100" type="submit">Sign in</button>
    </form>
</div>
</body>
</html>
