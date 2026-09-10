<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/**
 * Idempotent minimal schema matching live NewDev doj_cases.db columns
 * needed by the explorer (cases + key enrichment tables).
 */
final class Schema
{
    public static function migrate(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cases (
                id TEXT PRIMARY KEY,
                title TEXT,
                date TEXT,
                body TEXT,
                url TEXT,
                teaser TEXT,
                number TEXT,
                component TEXT,
                topic TEXT,
                changed TEXT,
                created TEXT,
                mentions_1960 NUM,
                mentions_crypto NUM,
                verified_1960,
                verified_crypto,
                classification TEXT
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_metadata (
                case_id TEXT PRIMARY KEY,
                district_office TEXT,
                usa_name TEXT,
                event_type TEXT,
                judge_name TEXT,
                judge_title TEXT,
                case_number TEXT,
                max_penalty_text TEXT,
                sentence_summary TEXT,
                money_amounts TEXT,
                crypto_assets TEXT,
                statutes_json TEXT,
                timeline_json TEXT,
                press_release_url TEXT,
                extras_json JSON,
                FOREIGN KEY(case_id) REFERENCES cases(id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS participants (
                participant_id INTEGER PRIMARY KEY AUTOINCREMENT,
                case_id TEXT,
                name TEXT,
                role TEXT,
                title TEXT,
                organization TEXT,
                location TEXT,
                age INTEGER,
                nationality TEXT,
                status TEXT,
                FOREIGN KEY(case_id) REFERENCES cases(id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS scraper_state (
                key TEXT PRIMARY KEY,
                value TEXT
            )'
        );
    }
}
