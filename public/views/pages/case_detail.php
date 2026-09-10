<?php
declare(strict_types=1);

/** @var array<string, mixed> $case */
/** @var array<string, mixed> $enrichment */
$case = $case ?? [];
$enrichment = $enrichment ?? [];
$e = static fn (mixed $v): string => \Project1960\View::e($v);
$meta = $enrichment['metadata'] ?? null;
?>
<div class="mb-3">
    <a href="/cases" class="btn btn-outline-secondary btn-sm">&larr; Back to cases</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h1 class="h3"><?= $e($case['title'] ?? 'Case') ?></h1>
        <p class="text-muted mb-2">
            <?= $e($case['date'] ?? '') ?>
            <?php if (!empty($case['url'])): ?>
                · <a href="<?= $e($case['url']) ?>" target="_blank" rel="noopener">Press release</a>
            <?php endif; ?>
        </p>
        <div class="mb-2">
            <?php if (!empty($case['mentions_1960'])): ?><span class="badge bg-primary me-1">Mentions 1960</span><?php endif; ?>
            <?php if (!empty($case['mentions_crypto'])): ?><span class="badge bg-success me-1">Crypto</span><?php endif; ?>
            <?php if (isset($case['verified_1960']) && (string) $case['verified_1960'] === '1'): ?>
                <span class="badge bg-info">Verified 1960</span>
            <?php endif; ?>
        </div>
        <div class="border rounded p-3 bg-light" style="white-space: pre-wrap;"><?= $e($case['body'] ?? '') ?></div>
    </div>
</div>

<?php
$sections = [
    'metadata' => 'Case metadata',
    'participants' => 'Participants',
    'agencies' => 'Agencies',
    'charges' => 'Charges',
    'financial_actions' => 'Financial actions',
    'victims' => 'Victims',
    'quotes' => 'Quotes',
    'themes' => 'Themes',
];
foreach ($sections as $key => $label):
    $data = $enrichment[$key] ?? ($key === 'metadata' ? null : []);
    $isEmpty = $key === 'metadata' ? ($data === null) : ($data === [] || $data === null);
    ?>
    <div class="card mb-3">
        <div class="card-header">
            <button class="btn btn-link text-decoration-none p-0" type="button"
                    data-bs-toggle="collapse" data-bs-target="#section-<?= $e($key) ?>"
                    aria-expanded="<?= $isEmpty ? 'false' : 'true' ?>">
                <?= $e($label) ?>
            </button>
        </div>
        <div id="section-<?= $e($key) ?>" class="collapse<?= $isEmpty ? '' : ' show' ?>">
            <div class="card-body">
                <?php if ($isEmpty): ?>
                    <p class="text-muted mb-0">No data.</p>
                <?php elseif ($key === 'metadata' && is_array($data)): ?>
                    <dl class="row mb-0">
                        <?php foreach ($data as $mk => $mv): ?>
                            <?php if ($mk === 'case_id') {
                                continue;
                            } ?>
                            <dt class="col-sm-3"><?= $e((string) $mk) ?></dt>
                            <dd class="col-sm-9"><?= $e(is_scalar($mv) || $mv === null ? (string) $mv : json_encode($mv)) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <?php foreach (array_keys($data[0] ?? []) as $col): ?>
                                        <?php if ($col === 'case_id') {
                                            continue;
                                        } ?>
                                        <th><?= $e((string) $col) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $col => $val): ?>
                                            <?php if ($col === 'case_id') {
                                                continue;
                                            } ?>
                                            <td><?= $e(is_scalar($val) || $val === null ? (string) $val : json_encode($val)) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
