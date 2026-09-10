<?php
declare(strict_types=1);

/** @var array<string, mixed> $stats */
/** @var list<array<string, mixed>> $activity_log */
/** @var list<array<string, mixed>> $cards */
$stats = $stats ?? [];
$activity_log = $activity_log ?? [];
$cards = $cards ?? [];
$e = static fn (mixed $v): string => \Project1960\View::e($v);

$formatTs = static function (string $ts): string {
    $ts = str_replace('T', ' ', $ts);

    return strlen($ts) >= 19 ? substr($ts, 0, 19) : $ts;
};
?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-body">
                <h1 class="h3 fw-bold text-primary mb-3">
                    <i class="bi bi-database me-2"></i>
                    Data Enrichment Progress
                </h1>
                <p class="text-muted mb-0">
                    Progress of AI-powered extraction for <strong>verified 18 U.S.C. § 1960 cases.</strong>
                    Each table is a different aspect of case analysis from the press releases.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card text-center h-100"><div class="card-body">
            <h3 class="text-primary"><?= $e($stats['total_cases'] ?? 0) ?></h3>
            <p class="text-muted mb-0">Total Cases</p>
        </div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-center h-100"><div class="card-body">
            <h3 class="text-success"><?= $e($stats['mentions_1960'] ?? 0) ?></h3>
            <p class="text-muted mb-0">Mentions 18 USC 1960</p>
        </div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-center h-100"><div class="card-body">
            <h3 class="text-info"><?= $e($stats['mentions_crypto'] ?? 0) ?></h3>
            <p class="text-muted mb-0">Mentions Cryptocurrency</p>
        </div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-center h-100"><div class="card-body">
            <h3 class="text-warning"><?= $e($stats['verified_yes'] ?? 0) ?></h3>
            <p class="text-muted mb-0">Verified 1960 Violations</p>
        </div></div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-table me-2"></i>Enrichment Tables Progress</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($cards as $card): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="card-title mb-0">
                                            <i class="bi <?= $e($card['icon'] ?? '') ?> me-2"></i>
                                            <?= $e($card['label'] ?? '') ?>
                                        </h6>
                                        <span class="badge <?= $e($card['badge'] ?? 'bg-secondary') ?>"><?= $e($card['count'] ?? 0) ?></span>
                                    </div>
                                    <p class="card-text small text-muted"><?= $e($card['blurb'] ?? '') ?></p>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar <?= $e($card['bar'] ?? 'bg-primary') ?>"
                                             style="width: <?= $e($card['percent'] ?? 0) ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?= $e($card['percent'] ?? 0) ?>% complete</small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>Recent Enrichment Activity Log</h5>
                <small class="text-muted">Click any Case ID to open case details</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Timestamp (UTC)</th>
                                <th scope="col">Case ID</th>
                                <th scope="col">Table</th>
                                <th scope="col">Status</th>
                                <th scope="col">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($activity_log === []): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No recent activity.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($activity_log as $log): ?>
                                <?php
                                $status = (string) ($log['status'] ?? '');
                                $badge = match ($status) {
                                    'success' => 'bg-success',
                                    'skipped' => 'bg-warning text-dark',
                                    default => 'bg-danger',
                                };
                                $label = match ($status) {
                                    'success' => 'Success',
                                    'skipped' => 'Skipped',
                                    default => 'Error',
                                };
                                $caseId = (string) ($log['case_id'] ?? '');
                                ?>
                                <tr>
                                    <td class="text-nowrap"><?= $e($formatTs((string) ($log['timestamp'] ?? ''))) ?></td>
                                    <td class="font-monospace small">
                                        <a href="/case.php?id=<?= $e(rawurlencode($caseId)) ?>" class="text-primary fw-bold text-decoration-none">
                                            <?= $e($caseId) ?>
                                            <i class="bi bi-box-arrow-up-right ms-1 opacity-75"></i>
                                        </a>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= $e($log['table_name'] ?? '') ?></span></td>
                                    <td><span class="badge <?= $e($badge) ?>"><?= $e($label) ?></span></td>
                                    <td class="small"><?= $e($log['notes'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
