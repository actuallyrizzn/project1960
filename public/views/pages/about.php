<?php
declare(strict_types=1);

/** @var array<string, mixed> $stats */
$stats = $stats ?? [
    'total_cases' => 0,
    'cases_1960' => 0,
    'cases_crypto' => 0,
];
$e = static fn (mixed $v): string => \Project1960\View::e($v);
?>
<div class="row">
    <div class="col-lg-8 mx-auto">
        <h1 class="display-5 mb-4">About Project 1960</h1>

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h4 card-title">Mission</h2>
                <p class="lead">
                    We track and analyze Department of Justice prosecutions under
                    <strong>18 U.S.C. § 1960</strong> (money transmission without a license),
                    with a particular focus on cryptocurrency-related cases.
                </p>
                <p class="mb-0">
                    This project creates an open dataset for researchers, journalists, and policymakers
                    to understand how federal authorities are enforcing money transmission laws in the digital age.
                </p>
            </div>
        </div>

        <div class="card mb-4 border-warning">
            <div class="card-body">
                <h2 class="h4 card-title">Independent research disclaimer</h2>
                <p>
                    Project 1960 is an <strong>independent research project</strong>. It is not affiliated with,
                    endorsed by, or operated by the U.S. Department of Justice or any other government agency.
                </p>
                <p class="mb-0">
                    Press-release text and classifications are provided for research and journalism.
                    Do not treat extracted enrichment fields as official court findings. Verify primary sources
                    (DOJ releases, dockets, counsel) before relying on any record for legal, commercial, or
                    enforcement decisions.
                </p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h4 card-title">What we do</h2>
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="h6">Data collection</h5>
                        <ul>
                            <li>Scrape DOJ press releases (2009–present)</li>
                            <li>Filter for 18 USC 1960 or cryptocurrency mentions</li>
                            <li>Store structured data in a searchable database</li>
                            <li>Idempotent scraping to avoid duplicates</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h5 class="h6">AI-powered extraction</h5>
                        <ul>
                            <li>LLM parsing of press releases</li>
                            <li>Structured rows in a relational schema</li>
                            <li>Participants, agencies, charges, and more</li>
                            <li>Tables suited for deep analysis</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h4 card-title">Methodology</h2>
                <h5 class="h6">Pipeline</h5>
                <ol>
                    <li><strong>Scraping</strong> — collect press releases from the DOJ API.</li>
                    <li><strong>Filtering</strong> — keyword identification of relevant cases.</li>
                    <li><strong>AI extraction</strong> — LLM analysis into structured entities.</li>
                    <li><strong>Storage</strong> — relational SQLite with enrichment tables.</li>
                </ol>
                <p class="mb-0">
                    Targeted prompts ask the model to act as a legal analyst reading each release and filling
                    normalized tables. Unstructured text becomes a queryable dataset.
                </p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h4 card-title">Relational schema</h2>
                <pre class="bg-light p-2 rounded mb-3"><code>cases (primary)
   ├─ case_metadata
   ├─ participants
   ├─ case_agencies
   ├─ charges
   ├─ financial_actions
   ├─ victims
   ├─ quotes
   └─ themes</code></pre>
                <p class="mb-0 text-muted small">
                    Hosted explorer: <code>project1960.rizzn.net</code> (PHP). Source:
                    <a href="https://github.com/actuallyrizzn/project1960" target="_blank" rel="noopener">GitHub</a>.
                </p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h4 card-title">Current status</h2>
                <div class="row">
                    <div class="col-md-4 text-center mb-3">
                        <div class="border rounded p-3">
                            <h3 class="text-primary"><?= $e($stats['total_cases'] ?? 0) ?></h3>
                            <p class="mb-0">Total cases</p>
                        </div>
                    </div>
                    <div class="col-md-4 text-center mb-3">
                        <div class="border rounded p-3">
                            <h3 class="text-success"><?= $e($stats['cases_1960'] ?? 0) ?></h3>
                            <p class="mb-0">18 USC 1960 mentions</p>
                        </div>
                    </div>
                    <div class="col-md-4 text-center mb-3">
                        <div class="border rounded p-3">
                            <h3 class="text-warning"><?= $e($stats['cases_crypto'] ?? 0) ?></h3>
                            <p class="mb-0">Crypto mentions</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h4 card-title">JSON API</h2>
                <ul class="mb-0">
                    <li><code>GET /api/stats</code> — dashboard statistics</li>
                    <li><code>GET /api/cases</code> — recent cases (limit 100)</li>
                    <li><code>GET /api/enrichment/{id}</code> — enrichment tables for a case</li>
                    <li><code>GET /health</code> — liveness</li>
                </ul>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h4 card-title">License &amp; links</h2>
                <p>
                    Licensed under
                    <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener">CC BY-SA 4.0</a>.
                </p>
                <ul class="mb-0">
                    <li><a href="https://github.com/actuallyrizzn/project1960" target="_blank" rel="noopener">GitHub repository</a></li>
                    <li><a href="/">Dashboard</a> · <a href="/cases">Browse cases</a></li>
                    <li><a href="https://www.law.cornell.edu/uscode/text/18/1960" target="_blank" rel="noopener">18 U.S.C. § 1960</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>
