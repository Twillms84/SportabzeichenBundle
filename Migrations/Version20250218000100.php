<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250218000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema for Sportabzeichen module with normalized disciplines, requirements, exams, participants, exam participants and exam results.';
    }

    public function up(Schema $schema): void
    {
        // ---------------------------------------------------------
        // 1. Disziplinen (NEU: Normalisierte Stammdaten)
        // ---------------------------------------------------------
        $this->addSql("
            CREATE TABLE sportabzeichen_disciplines (
                id              SERIAL PRIMARY KEY,
                name            TEXT NOT NULL,
                kategorie       TEXT NOT NULL,
                einheit         TEXT NOT NULL,
                berechnungsart  TEXT NOT NULL DEFAULT 'GREATER',
                created_at      TIMESTAMPTZ DEFAULT NOW()
            );
        ");

        // ---------------------------------------------------------
        // 2. Prüfungen
        // ---------------------------------------------------------
        $this->addSql("
            CREATE TABLE sportabzeichen_exams (
                id SERIAL PRIMARY KEY,
                exam_date   DATE,
                exam_year   INT NOT NULL,
                created_at  TIMESTAMPTZ DEFAULT NOW(),
                updated_at  TIMESTAMPTZ DEFAULT NOW()
            );
        ");

        // ---------------------------------------------------------
        // 3. Teilnehmer
        // ---------------------------------------------------------
        $this->addSql("
            CREATE TABLE sportabzeichen_participants (
                id              SERIAL PRIMARY KEY,
                import_id       TEXT NOT NULL UNIQUE,
                vorname         TEXT,
                nachname        TEXT,
                geschlecht      TEXT,
                geburtsdatum    DATE,
                updated_at      TIMESTAMPTZ DEFAULT NOW()
            );
        ");

        // ---------------------------------------------------------
        // 4. Anforderungen (NEU: Disziplin-FK, UNIQUE constraint)
        // ---------------------------------------------------------
        $this->addSql("
            CREATE TABLE sportabzeichen_requirements (
                id              SERIAL PRIMARY KEY,
                discipline_id   INT NOT NULL REFERENCES sportabzeichen_disciplines(id) ON DELETE CASCADE,
                jahr            INT NOT NULL,
                auswahlnummer   INT NOT NULL,
                altersklasse    TEXT NOT NULL,
                geschlecht      TEXT NOT NULL,
                bronze          DOUBLE PRECISION,
                silber          DOUBLE PRECISION,
                gold            DOUBLE PRECISION,
                schwimmnachweis BOOLEAN DEFAULT FALSE
            );
        ");

        // UNIQUE für ON CONFLICT im CSV-Importer
        $this->addSql("
            ALTER TABLE sportabzeichen_requirements
                ADD CONSTRAINT uniq_requirements
                UNIQUE (discipline_id, jahr, altersklasse, geschlecht);
        ");

        // ---------------------------------------------------------
        // 5. Prüfungs-Teilnehmer (Exam ↔ Participant)
        // ---------------------------------------------------------
        $this->addSql("
            CREATE TABLE sportabzeichen_exam_participants (
                id              SERIAL PRIMARY KEY,
                exam_id         INT NOT NULL REFERENCES sportabzeichen_exams(id) ON DELETE CASCADE,
                participant_id  INT NOT NULL REFERENCES sportabzeichen_participants(id) ON DELETE CASCADE,
                age_year        INT NOT NULL,
                UNIQUE (exam_id, participant_id)
            );
        ");

        // ---------------------------------------------------------
        // 6. Prüfungsergebnisse (NORMALISIERT: discipline_id statt Text)
        // ---------------------------------------------------------
        $this->addSql("
            CREATE TABLE sportabzeichen_exam_results (
                id              SERIAL PRIMARY KEY,
                ep_id           INT NOT NULL REFERENCES sportabzeichen_exam_participants(id) ON DELETE CASCADE,
                discipline_id   INT NOT NULL REFERENCES sportabzeichen_disciplines(id),
                leistung        DOUBLE PRECISION,
                stufe           TEXT,
                points          INT,
                created_at      TIMESTAMPTZ DEFAULT NOW()
            );
        ");

        // ---------------------------------------------------------
        // 7. GRANTS für IServ / Symfony
        // ---------------------------------------------------------
        $tables = [
            'sportabzeichen_disciplines',
            'sportabzeichen_exams',
            'sportabzeichen_participants',
            'sportabzeichen_requirements',
            'sportabzeichen_exam_participants',
            'sportabzeichen_exam_results'
        ];

        foreach ($tables as $table) {
            $seq = "{$table}_id_seq";

            $this->addSql("GRANT SELECT, INSERT, UPDATE, DELETE ON $table TO symfony;");
            $this->addSql("GRANT USAGE, SELECT ON SEQUENCE $seq TO symfony;");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP TABLE IF EXISTS sportabzeichen_exam_results;");
        $this->addSql("DROP TABLE IF EXISTS sportabzeichen_exam_participants;");
        $this->addSql("DROP TABLE IF EXISTS sportabzeichen_requirements;");
        $this->addSql("DROP TABLE IF EXISTS sportabzeichen_disciplines;");
        $this->addSql("DROP TABLE IF EXISTS sportabzeichen_participants;");
        $this->addSql("DROP TABLE IF EXISTS sportabzeichen_exams;");
    }
}
