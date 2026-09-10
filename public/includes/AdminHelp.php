<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;

/** Operator help topics for /admin/help (AD-A6). */
final class AdminHelp
{
    /** @return list<array{slug: string, title: string, summary: string}> */
    public static function topics(): array
    {
        return [
            [
                'slug' => 'bootstrap',
                'title' => 'Bootstrap first operator',
                'summary' => 'Create the first admin user with bin/bootstrap-admin.php and vault pass file.',
            ],
            [
                'slug' => 'api-scopes',
                'title' => 'API key scopes',
                'summary' => 'Lean scope pack for public explorer and admin JSON routes.',
            ],
            [
                'slug' => 'enrichment-math',
                'title' => 'Enrichment progress math',
                'summary' => 'How verified-cohort numerators keep public bars ≤ 100%.',
            ],
            [
                'slug' => 'pipeline-cli',
                'title' => 'Pipeline CLIs',
                'summary' => 'Scrape, match, ingest, OCR, extract entrypoints under bin/.',
            ],
        ];
    }

    public static function normalizeSlug(?string $slug): ?string
    {
        $s = strtolower(trim((string) $slug));
        foreach (self::topics() as $t) {
            if ($t['slug'] === $s) {
                return $s;
            }
        }

        return null;
    }

    /** @return array{slug: string, title: string, summary: string, body: string} */
    public static function topic(string $slug): array
    {
        $norm = self::normalizeSlug($slug);
        if ($norm === null) {
            throw new InvalidArgumentException('Unknown help topic');
        }
        foreach (self::topics() as $t) {
            if ($t['slug'] === $norm) {
                return $t + ['body' => self::bodyFor($norm)];
            }
        }

        throw new InvalidArgumentException('Unknown help topic');
    }

    private static function bodyFor(string $slug): string
    {
        return match ($slug) {
            'bootstrap' => <<<'TXT'
Use `php bin/bootstrap-admin.php` on the host (or locally against the SQLite path).
Credentials for the first operator live in `~/.ssh/project1960-admin.pass` (vault) — never commit passwords.
After bootstrap, sign in at `/admin/login`. Additional operators are created under Users.
TXT,
            'api-scopes' => <<<'TXT'
Public explorer JSON stays anonymous-OK by default. Presenting a key requires a valid scoped key.
Admin JSON always requires a key. Lean scopes include:
- stats:read, cases:read, enrichment:read, patterns:read
- admin:read, admin:users, admin:keys, admin:settings
- pipeline:status, pipeline:enqueue
Set `P1960_API_REQUIRE_KEY=1` to require keys on public routes too.
TXT,
            'enrichment-math' => <<<'TXT'
Public `/enrichment` bars use the verified §1960 cohort as the denominator (`verified_yes`).
Numerators count only verified cases that have that enrichment field filled — never all enriched rows.
Bars are capped at 100% in the UI. If a bar looks wrong, check `/api/stats/` enrichment vs verified_yes.
TXT,
            'pipeline-cli' => <<<'TXT'
Operator CLIs (run from repo root with the host PHP + DB path configured):
- `bin/scrape.php` — DOJ scrape loop
- `bin/match.php` — CourtListener docket match
- `bin/ingest-docs.php` / `bin/download-docs.php` / `bin/recap-fetch.php` — document intake
- `bin/ocr-docs.php` — OCR
- `bin/extract-people.php` — Venice extract
- `bin/patterns-export.php` — pattern export
Prefer dry-run / limited batches from the Pipeline admin panel. Unlimited enrich burn is blocked from the UI.
TXT,
            default => '',
        };
    }
}
