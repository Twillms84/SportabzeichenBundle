--
-- Sportabzeichen Modul – Datenbankschema
-- Version 2.1.0 (bereinigt, import_id-basiert)
-- Kompatibel mit IServ / PostgreSQL
--

------------------------------------------------------------
-- 1. Prüfungen
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sportabzeichen_exams (
    id          SERIAL PRIMARY KEY,
    exam_name   TEXT,
    exam_date   DATE,
    exam_year   INT NOT NULL,
    created_at  TIMESTAMPTZ DEFAULT NOW(),
    updated_at  TIMESTAMPTZ DEFAULT NOW()
);

------------------------------------------------------------
-- 2. Teilnehmer
-- Referenz über import_id (IServ users.importid)
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sportabzeichen_participants (
    id              SERIAL PRIMARY KEY,

    import_id       TEXT NOT NULL UNIQUE,

    geschlecht      TEXT CHECK (geschlecht IN ('MALE','FEMALE')),
    geburtsdatum    DATE,

    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

------------------------------------------------------------
-- 3. Disziplinen (Stammdaten)
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sportabzeichen_disciplines (
    id              SERIAL PRIMARY KEY,

    name            TEXT NOT NULL,
    kategorie       TEXT NOT NULL,
    einheit         TEXT NOT NULL,
    berechnungsart  TEXT NOT NULL DEFAULT 'GREATER',

    created_at      TIMESTAMPTZ DEFAULT NOW(),

    CONSTRAINT uniq_sportabzeichen_disciplines_name
        UNIQUE (name)
);

------------------------------------------------------------
-- 4. Anforderungen (DOSB-Katalog)
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sportabzeichen_requirements (
    id              SERIAL PRIMARY KEY,

    discipline_id   INT NOT NULL
        REFERENCES sportabzeichen_disciplines(id)
        ON DELETE CASCADE,

    jahr            INT NOT NULL,
    age_min         INT NOT NULL,
    age_max         INT NOT NULL,

    geschlecht      TEXT NOT NULL
        CHECK (geschlecht IN ('MALE','FEMALE')),

    auswahlnummer   INT NOT NULL,

    bronze          DOUBLE PRECISION,
    silber          DOUBLE PRECISION,
    gold            DOUBLE PRECISION,

    schwimmnachweis BOOLEAN DEFAULT FALSE
);

-- Eindeutigkeit (CSV-UPSERT)
CREATE UNIQUE INDEX IF NOT EXISTS uniq_sportabzeichen_requirements
ON sportabzeichen_requirements
(discipline_id, jahr, age_min, age_max, geschlecht);

-- Lookup / Performance
CREATE INDEX IF NOT EXISTS idx_req_lookup
ON sportabzeichen_requirements
(jahr, geschlecht, age_min, age_max);

------------------------------------------------------------
-- 5. Prüfungs-Teilnehmer
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sportabzeichen_exam_participants (
    id              SERIAL PRIMARY KEY,

    exam_id         INT NOT NULL
        REFERENCES sportabzeichen_exams(id)
        ON DELETE CASCADE,

    participant_id  INT NOT NULL
        REFERENCES sportabzeichen_participants(id)
        ON DELETE CASCADE,

    age_year        INT NOT NULL,

    UNIQUE (exam_id, participant_id)
);

CREATE INDEX IF NOT EXISTS idx_exam_participant_exam
ON sportabzeichen_exam_participants (exam_id);

------------------------------------------------------------
-- 6. Ergebnisse
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sportabzeichen_exam_results (
    id              SERIAL PRIMARY KEY,

    ep_id           INT NOT NULL
        REFERENCES sportabzeichen_exam_participants(id)
        ON DELETE CASCADE,

    discipline_id   INT NOT NULL
        REFERENCES sportabzeichen_disciplines(id),

    leistung        DOUBLE PRECISION,
    stufe           TEXT,

    CONSTRAINT uniq_exam_result UNIQUE (ep_id, discipline_id)
);
------------------------------------------------------------
-- 7. Rechte für Symfony / IServ
------------------------------------------------------------

GRANT SELECT, INSERT, UPDATE, DELETE
ON
    sportabzeichen_exams,
    sportabzeichen_participants,
    sportabzeichen_disciplines,
    sportabzeichen_requirements,
    sportabzeichen_exam_participants,
    sportabzeichen_exam_results
TO symfony;

GRANT USAGE, SELECT
ON ALL SEQUENCES IN SCHEMA public
TO symfony;

------------------------------------------------------------
-- SCHEMA FERTIG
------------------------------------------------------------
