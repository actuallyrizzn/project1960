<?php
declare(strict_types=1);
/** @var string $heading */
/** @var string $blurb */
$heading = $heading ?? 'Page';
$blurb = $blurb ?? '';
?>
<div class="row">
    <div class="col-12">
        <h1 class="mb-3"><?= \Project1960\View::e($heading) ?></h1>
        <p class="text-muted"><?= \Project1960\View::e($blurb) ?></p>
    </div>
</div>
