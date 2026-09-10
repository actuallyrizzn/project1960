<?php
declare(strict_types=1);

/** @var list<array<string, mixed>> $cases */
/** @var int $total */
/** @var int $page */
/** @var int $total_pages */
/** @var \Project1960\CaseListFilters $filters */
$cases = $cases ?? [];
$total = $total ?? 0;
$page = $page ?? 1;
$total_pages = $total_pages ?? 1;
$filters = $filters ?? new \Project1960\CaseListFilters();
$e = static fn (mixed $v): string => \Project1960\View::e($v);

$selected = static function (string $current, string $value) use ($e): string {
    return $current === $value ? ' selected' : '';
};

$queryKeep = static function (array $overrides) use ($filters): string {
    $q = [
        'search' => $filters->search,
        'classification' => $filters->classification,
        'mentions_1960' => $filters->mentions1960,
        'mentions_crypto' => $filters->mentionsCrypto,
        'verified_1960' => $filters->verified1960,
    ];
    foreach ($overrides as $k => $v) {
        $q[$k] = $v;
    }
    $q = array_filter($q, static fn ($v) => $v !== '' && $v !== null);

    return http_build_query($q);
};
?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="display-6 fw-bold text-primary mb-3">
            <i class="bi bi-list-ul me-2"></i>
            Cases Database
        </h1>
        <p class="text-muted">Browse and filter DOJ press releases (<?= $e($total) ?> total cases)</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="/cases" class="row g-3">
            <div class="col-md-3">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search"
                       value="<?= $e($filters->search) ?>" placeholder="Search titles and content...">
            </div>
            <div class="col-md-2">
                <label for="classification" class="form-label">Classification</label>
                <select class="form-select" id="classification" name="classification">
                    <option value="">All</option>
                    <option value="yes"<?= $selected($filters->classification, 'yes') ?>>Yes (1960)</option>
                    <option value="no"<?= $selected($filters->classification, 'no') ?>>No</option>
                    <option value="unknown"<?= $selected($filters->classification, 'unknown') ?>>Unknown</option>
                    <option value="verified"<?= $selected($filters->classification, 'verified') ?>>Verified</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="mentions_1960" class="form-label">Mentions 1960</label>
                <select class="form-select" id="mentions_1960" name="mentions_1960">
                    <option value="">All</option>
                    <option value="1"<?= $selected($filters->mentions1960, '1') ?>>Yes</option>
                    <option value="0"<?= $selected($filters->mentions1960, '0') ?>>No</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="mentions_crypto" class="form-label">Mentions Crypto</label>
                <select class="form-select" id="mentions_crypto" name="mentions_crypto">
                    <option value="">All</option>
                    <option value="1"<?= $selected($filters->mentionsCrypto, '1') ?>>Yes</option>
                    <option value="0"<?= $selected($filters->mentionsCrypto, '0') ?>>No</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="verified_1960" class="form-label">Verified 1960</label>
                <select class="form-select" id="verified_1960" name="verified_1960">
                    <option value="">All</option>
                    <option value="1"<?= $selected($filters->verified1960, '1') ?>>Yes</option>
                    <option value="0"<?= $selected($filters->verified1960, '0') ?>>No</option>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
        <div class="mt-2">
            <a href="/cases" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Date</th>
                        <th>Classification</th>
                        <th>Mentions</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($cases === []): ?>
                        <tr><td colspan="5" class="text-muted">No cases match these filters.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($cases as $case): ?>
                        <tr>
                            <td><?= $e($case['title'] ?? '') ?></td>
                            <td><?= $e($case['date'] ?? '') ?></td>
                            <td><?= $e($case['classification'] ?? '') ?></td>
                            <td>
                                <?php if (!empty($case['mentions_1960'])): ?><span class="badge bg-primary me-1">1960</span><?php endif; ?>
                                <?php if (!empty($case['mentions_crypto'])): ?><span class="badge bg-success">Crypto</span><?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="/case.php?id=<?= $e(rawurlencode((string) ($case['id'] ?? ''))) ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav aria-label="Cases pagination" class="mt-3">
                <ul class="pagination justify-content-center">
                    <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                        <a class="page-link" href="/cases?<?= $e($queryKeep(['page' => (string) max(1, $page - 1)])) ?>">Prev</a>
                    </li>
                    <li class="page-item disabled"><span class="page-link">Page <?= $e($page) ?> / <?= $e($total_pages) ?></span></li>
                    <li class="page-item<?= $page >= $total_pages ? ' disabled' : '' ?>">
                        <a class="page-link" href="/cases?<?= $e($queryKeep(['page' => (string) min($total_pages, $page + 1)])) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>
