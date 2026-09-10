<?php
declare(strict_types=1);

/** @var array<string, mixed> $stats */
$stats = $stats ?? [
    'total_cases' => 0,
    'mentions_1960' => 0,
    'mentions_crypto' => 0,
    'verified_yes' => 0,
    'verified_no' => 0,
    'unprocessed_1960' => 0,
    'enrichment' => [],
];
$e = static fn (mixed $v): string => \Project1960\View::e($v);
?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="display-5 fw-bold text-primary mb-3">
            <i class="bi bi-graph-up me-2"></i>
            Project 1960 Dashboard
        </h1>
        <p class="lead text-muted">
            Explore and analyze Department of Justice press releases for 18 USC 1960 violations
        </p>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card stat-card h-100">
            <div class="card-body text-center">
                <h2 class="mb-0 fw-bold"><?= $e($stats['total_cases']) ?></h2>
                <p class="mb-0 opacity-75">Total Cases</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card success h-100">
            <div class="card-body text-center">
                <h2 class="mb-0 fw-bold"><?= $e($stats['verified_yes']) ?></h2>
                <p class="mb-0 opacity-75">Verified 1960</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card warning h-100">
            <div class="card-body text-center">
                <h2 class="mb-0 fw-bold"><?= $e($stats['verified_no']) ?></h2>
                <p class="mb-0 opacity-75">Not 1960</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card info h-100">
            <div class="card-body text-center">
                <h2 class="mb-0 fw-bold"><?= $e($stats['unprocessed_1960']) ?></h2>
                <p class="mb-0 opacity-75">Unprocessed 1960</p>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">Mentions Analysis</h5>
                <div class="row text-center">
                    <div class="col-6">
                        <h3 class="text-primary fw-bold"><?= $e($stats['mentions_1960']) ?></h3>
                        <p class="text-muted mb-0">Mention 18 USC 1960</p>
                    </div>
                    <div class="col-6">
                        <h3 class="text-success fw-bold"><?= $e($stats['mentions_crypto']) ?></h3>
                        <p class="text-muted mb-0">Mention Cryptocurrency</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-body">
                <h5 class="card-title">Enrichment Progress</h5>
                <ul class="list-unstyled mb-0">
                    <?php foreach (($stats['enrichment'] ?? []) as $table => $count): ?>
                        <li class="d-flex justify-content-between border-bottom py-1">
                            <span><?= $e($table) ?></span>
                            <strong><?= $e($count) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
