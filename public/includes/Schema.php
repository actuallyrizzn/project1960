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

        // CourtListener Phase 2 — docket cache + case links (CL-S1)
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS courtlistener_dockets (
                cl_docket_id INTEGER PRIMARY KEY,
                court_id TEXT,
                docket_number TEXT,
                case_name TEXT,
                raw_json TEXT,
                updated_at TEXT
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS case_courtlistener_links (
                case_id TEXT NOT NULL,
                cl_docket_id INTEGER NOT NULL,
                match_confidence REAL,
                match_method TEXT,
                raw_json TEXT,
                updated_at TEXT,
                PRIMARY KEY (case_id, cl_docket_id),
                FOREIGN KEY(case_id) REFERENCES cases(id),
                FOREIGN KEY(cl_docket_id) REFERENCES courtlistener_dockets(cl_docket_id)
            )'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_case_cl_links_cl_docket
             ON case_courtlistener_links(cl_docket_id)'
        );

        // CL-S2 — RECAP/CL documents + optional page text + OCR status
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS courtlistener_documents (
                cl_document_id INTEGER PRIMARY KEY,
                cl_docket_id INTEGER,
                entry_number TEXT,
                description TEXT,
                filepath_or_url TEXT,
                mime TEXT,
                has_plaintext INTEGER NOT NULL DEFAULT 0,
                ocr_status TEXT NOT NULL DEFAULT \'none\',
                byte_size INTEGER,
                fetched_at TEXT,
                raw_json TEXT,
                updated_at TEXT,
                FOREIGN KEY(cl_docket_id) REFERENCES courtlistener_dockets(cl_docket_id),
                CHECK (ocr_status IN (\'none\', \'pending\', \'done\', \'failed\'))
            )'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_cl_documents_docket
             ON courtlistener_documents(cl_docket_id)'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_cl_documents_ocr
             ON courtlistener_documents(ocr_status)'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS courtlistener_document_pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cl_document_id INTEGER NOT NULL,
                page_number INTEGER NOT NULL,
                page_text TEXT,
                UNIQUE(cl_document_id, page_number),
                FOREIGN KEY(cl_document_id) REFERENCES courtlistener_documents(cl_document_id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS courtlistener_document_text (
                cl_document_id INTEGER PRIMARY KEY,
                full_text TEXT,
                updated_at TEXT,
                FOREIGN KEY(cl_document_id) REFERENCES courtlistener_documents(cl_document_id)
            )'
        );

        // CL-S3 — CL extract people (parallel to press-release participants)
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cl_persons (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                normalized_name TEXT NOT NULL,
                display_name TEXT,
                role TEXT NOT NULL DEFAULT \'other\',
                organization TEXT,
                source_document_id INTEGER,
                confidence REAL,
                raw_json TEXT,
                updated_at TEXT,
                CHECK (role IN (\'defendant\', \'attorney\', \'judge\', \'witness\', \'other\')),
                FOREIGN KEY(source_document_id) REFERENCES courtlistener_documents(cl_document_id)
            )'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_cl_persons_normalized
             ON cl_persons(normalized_name)'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_cl_persons_role
             ON cl_persons(role)'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cl_person_aliases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                person_id INTEGER NOT NULL,
                alias_normalized TEXT NOT NULL,
                alias_display TEXT,
                UNIQUE(person_id, alias_normalized),
                FOREIGN KEY(person_id) REFERENCES cl_persons(id)
            )'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_cl_person_aliases_norm
             ON cl_person_aliases(alias_normalized)'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cl_person_case_edges (
                person_id INTEGER NOT NULL,
                case_id TEXT NOT NULL,
                role TEXT,
                confidence REAL,
                source_document_id INTEGER,
                updated_at TEXT,
                PRIMARY KEY (person_id, case_id),
                FOREIGN KEY(person_id) REFERENCES cl_persons(id),
                FOREIGN KEY(case_id) REFERENCES cases(id),
                FOREIGN KEY(source_document_id) REFERENCES courtlistener_documents(cl_document_id)
            )'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_cl_person_case_edges_case
             ON cl_person_case_edges(case_id)'
        );
    }
}
