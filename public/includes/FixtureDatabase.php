<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/**
 * Creates a temporary SQLite file with schema + seed rows for tests.
 */
final class FixtureDatabase
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (sys_get_temp_dir() . '/p1960_fixture_' . bin2hex(random_bytes(8)) . '.db');
        if (is_file($this->path)) {
            unlink($this->path);
        }
        $pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        Schema::migrate($pdo);
        $this->seed($pdo);
        $pdo = null;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function pdo(): PDO
    {
        return Database::connect($this->path);
    }

    public function destroy(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function seed(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO cases (id, title, date, body, url, teaser, number, mentions_1960, mentions_crypto, verified_1960, verified_crypto, classification)
             VALUES (
               'fixture-case-1',
               'United States v. Fixture',
               '2024-01-15',
               'Defendant charged under 18 U.S.C. 1960.',
               'https://www.justice.gov/example/fixture-case-1',
               'Fixture teaser',
               '1:24-cr-0001',
               1,
               1,
               1,
               1,
               'verified'
             )"
        );

        $pdo->exec(
            "INSERT INTO case_metadata (case_id, district_office, case_number, event_type, press_release_url)
             VALUES (
               'fixture-case-1',
               'Southern District of New York',
               '1:24-cr-0001',
               'Indictment',
               'https://www.justice.gov/example/fixture-case-1'
             )"
        );

        $pdo->exec(
            "INSERT INTO participants (case_id, name, role, organization)
             VALUES ('fixture-case-1', 'Jane Fixture', 'defendant', NULL)"
        );

        // Optional enrichment tables (idempotent) for detail page coverage
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS charges (
                charge_id INTEGER PRIMARY KEY AUTOINCREMENT,
                case_id TEXT,
                charge_description TEXT,
                statute TEXT,
                severity TEXT,
                max_penalty TEXT,
                fine_amount TEXT,
                defendant TEXT,
                status TEXT,
                FOREIGN KEY(case_id) REFERENCES cases(id)
            )'
        );
        $pdo->exec(
            "INSERT INTO charges (case_id, charge_description, statute, defendant)
             VALUES ('fixture-case-1', 'Unlicensed money transmission', '18 U.S.C. § 1960', 'Jane Fixture')"
        );

        $pdo->exec(
            "INSERT INTO cases (id, title, date, body, url, mentions_1960, verified_1960)
             VALUES (
               'fixture-case-2',
               'Unverified crypto mention',
               '2023-06-01',
               'Mentions cryptocurrency only.',
               'https://www.justice.gov/example/fixture-case-2',
               0,
               0
             )"
        );
    }
}
