--
-- Sportabzeichen Modul – Datenbankschema
-- Version 1.0.0
-- Kompatibel mit IServ / PostgreSQL
--

------------------------------------------------------------
-- 1. Haupttabellen
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sportabzeichen_exams (
    id          SERIAL PRIMARY KEY,
    exam_name   TEXT,
    exam_date   DATE,
    exam_year   INT NOT NULL,
    created_at  TIMESTAMPTZ DEFAULT NOW(),
    updated_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS sportabzeichen_participants (
    id              SERIAL PRIMARY KEY,
    import_id       TEXT NOT NULL UNIQUE,
    vorname         TEXT,
    nachname        TEXT,
    geschlecht      TEXT,
    geburtsdatum    DATE,
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

------------------------------------------------------------
-- 2. Anforderungen (DOSB-Katalog-Daten)
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sportabzeichen_requirements (
    id              SERIAL PRIMARY KEY,
    jahr            INT NOT NULL,
    altersklasse    TEXT NOT NULL,
    geschlecht      TEXT NOT NULL,      -- MALE / FEMALE / DIVERS
    auswahlnummer   INT NOT NULL,       -- Reihenfolge im Dropdown
    disziplin       TEXT NOT NULL,
    kategorie       TEXT NOT NULL,
    bronze          DOUBLE PRECISION,
    silber          DOUBLE PRECISION,
    gold            DOUBLE PRECISION,
    einheit         TEXT,
    schwimmnachweis BOOLEAN DEFAULT FALSE,
    berechnungsart  TEXT DEFAULT 'GREATER'
);

CREATE INDEX IF NOT EXISTS idx_req_lookup
ON sportabzeichen_requirements (jahr, altersklasse, geschlecht, disziplin);

------------------------------------------------------------
-- 3. Verknüpfung Teilnehmer ↔ Prüfungen
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sportabzeichen_exam_participants (
    id              SERIAL PRIMARY KEY,
    exam_id         INT NOT NULL REFERENCES sportabzeichen_exams(id) ON DELETE CASCADE,
    participant_id  INT NOT NULL REFERENCES sportabzeichen_participants(id) ON DELETE CASCADE,
    age_year        INT NOT NULL,
    UNIQUE (exam_id, participant_id)
);

CREATE INDEX IF NOT EXISTS idx_exam_participant_exam
ON sportabzeichen_exam_participants (exam_id);

------------------------------------------------------------
-- 4. Ergebnisse je Disziplin
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sportabzeichen_exam_results (
    id              SERIAL PRIMARY KEY,
    ep_id           INT NOT NULL REFERENCES sportabzeichen_exam_participants(id) ON DELETE CASCADE,
    disziplin       TEXT NOT NULL,
    kategorie       TEXT NOT NULL,
    auswahlnummer   INT NOT NULL,
    leistung        DOUBLE PRECISION,
    stufe           TEXT
);

CREATE INDEX IF NOT EXISTS idx_results_ep
ON sportabzeichen_exam_results (ep_id);

------------------------------------------------------------
-- 5. Berechtigungen für IServ / Symfony
------------------------------------------------------------

-- Sequences
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO symfony;

-- Tabellen
GRANT SELECT, INSERT, UPDATE, DELETE ON sportabzeichen_exams TO symfony;
GRANT SELECT, INSERT, UPDATE, DELETE ON sportabzeichen_participants TO symfony;
GRANT SELECT, INSERT, UPDATE, DELETE ON sportabzeichen_requirements TO symfony;
GRANT SELECT, INSERT, UPDATE, DELETE ON sportabzeichen_exam_participants TO symfony;
GRANT SELECT, INSERT, UPDATE, DELETE ON sportabzeichen_exam_results TO symfony;

------------------------------------------------------------
-- SCHEMA FERTIG
------------------------------------------------------------

