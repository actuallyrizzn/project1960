<?php
declare(strict_types=1);

/** @var string $q */
/** @var list<array<string, mixed>> $results */
/** @var list<array<string, mixed>> $multi */
$q = $q ?? '';
$results = $results ?? [];
$multi = $multi ?? [];
$e = static fn (mixed $v): string => \Project1960\View::e($v);
?>
<h1 class="h3 mb-3">Person / network patterns</h1>
<p class="text-muted">Search a person linked across §1960 cases, or browse multi-case names.</p>

<form class="row g-2 mb-4" method="get" action="/patterns">
    <div class="col-md-8">
        <input type="search" class="form-control" name="q" value="<?= $e($q) ?>"
               placeholder="Person name (e.g. counsel or defendant)">
    </div>
    <div class="col-md-4">
        <button type="submit" class="btn btn-primary">Search</button>
    </div>
</form>

<?php if ($q !== ''): ?>
    <h2 class="h5">Results for “<?= $e($q) ?>”</h2>
    <?php if ($results === []): ?>
        <p class="text-muted">No linked cases.</p>
    <?php else: ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm">
                <thead><tr><th>Person</th><th>Role</th><th>Case</th></tr></thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?= $e($row['display_name'] ?? '') ?></td>
                        <td><?= $e($row['role'] ?? '') ?></td>
                        <td><a href="/case/<?= $e(rawurlencode((string) ($row['case_id'] ?? ''))) ?>"><?= $e($row['case_id'] ?? '') ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<h2 class="h5">People in multiple cases</h2>
<?php if ($multi === []): ?>
    <p class="text-muted mb-0">No multi-case links yet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Name</th><th>Role</th><th># cases</th><th>Cases</th></tr></thead>
            <tbody>
            <?php foreach ($multi as $row): ?>
                <tr>
                    <td><?= $e($row['display_name'] ?? '') ?></td>
                    <td><?= $e($row['role'] ?? '') ?></td>
                    <td><?= $e($row['case_count'] ?? '') ?></td>
                    <td>
                        <?php foreach (($row['case_ids'] ?? []) as $cid): ?>
                            <a class="me-1" href="/case/<?= $e(rawurlencode((string) $cid)) ?>"><?= $e($cid) ?></a>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
