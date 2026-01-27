--
-- Sportabzeichen Modul – Datenbankschema
-- Version 2.4.0 (DOSB)
--

------------------------------------------------------------
-- 1. Stammdaten & Teilnehmer
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_exams (
    id          SERIAL PRIMARY KEY,
    exam_name   TEXT,
    exam_date   DATE,
    exam_year   INT NOT NULL,
    created_at  TIMESTAMPTZ DEFAULT NOW(),
    updated_at  TIMESTAMPTZ DEFAULT NOW(),
    creator_id  TEXT
);

CREATE TABLE IF NOT EXISTS sportabzeichen_participants (
    id              SERIAL PRIMARY KEY,
    import_id       TEXT NOT NULL UNIQUE,
    geschlecht      TEXT CHECK (geschlecht IN ('MALE','FEMALE')),
    geburtsdatum    DATE,
    updated_at      TIMESTAMPTZ DEFAULT NOW(),
    user_id         INT REFERENCES users(id) ON DELETE SET NULL,
    username        VARCHAR(255)
);
-- Index für Performance (aus Live-DB)
CREATE INDEX IF NOT EXISTS idx_sportabzeichen_user ON sportabzeichen_participants(user_id);


------------------------------------------------------------
-- 2. DOSB Schwimmnachweis
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_swimming_proofs (
    id                  SERIAL PRIMARY KEY,
    participant_id      INT NOT NULL REFERENCES sportabzeichen_participants(id) ON DELETE CASCADE,
    confirmed_at        DATE NOT NULL,
    valid_until         DATE NOT NULL,
    requirement_met_via VARCHAR(255),
    confirmed_by_user   VARCHAR(255),
    created_at          TIMESTAMPTZ DEFAULT NOW(),
    exam_year           INT,
    CONSTRAINT unique_participant_proof_year UNIQUE (participant_id, exam_year)
);
-- Indexe aus Live-DB
CREATE INDEX IF NOT EXISTS idx_swimming_proofs_exam_year ON sportabzeichen_swimming_proofs(exam_year);
CREATE INDEX IF NOT EXISTS idx_swimming_validity ON sportabzeichen_swimming_proofs(participant_id, valid_until);


------------------------------------------------------------
-- 3. Disziplinen & Anforderungen
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_disciplines (
    id              SERIAL PRIMARY KEY,
    name            TEXT NOT NULL,
    kategorie       TEXT NOT NULL,
    einheit         TEXT NOT NULL,
    berechnungsart  TEXT NOT NULL DEFAULT 'GREATER',
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    verband         TEXT,
    CONSTRAINT uniq_sportabzeichen_disciplines_name UNIQUE (name)
);
CREATE INDEX IF NOT EXISTS idx_disciplines_verband ON sportabzeichen_disciplines(verband);

CREATE TABLE IF NOT EXISTS sportabzeichen_requirements (
    id              SERIAL PRIMARY KEY,
    discipline_id   INT NOT NULL REFERENCES sportabzeichen_disciplines(id) ON DELETE CASCADE,
    jahr            INT NOT NULL,
    age_min         INT NOT NULL,
    age_max         INT NOT NULL,
    geschlecht      TEXT NOT NULL CHECK (geschlecht IN ('MALE','FEMALE')),
    auswahlnummer   INT NOT NULL,
    bronze          DOUBLE PRECISION,
    silber          DOUBLE PRECISION,
    gold            DOUBLE PRECISION,
    schwimmnachweis BOOLEAN DEFAULT FALSE,
    CONSTRAINT uniq_sportabzeichen_requirements UNIQUE (discipline_id, jahr, age_min, age_max, geschlecht),
    CONSTRAINT chk_age_range CHECK (age_max >= age_min)
);
CREATE INDEX IF NOT EXISTS idx_req_lookup ON sportabzeichen_requirements(jahr, geschlecht, age_min, age_max);


------------------------------------------------------------
-- 4. Prüfungs-Teilnehmer
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_exam_participants (
    id              SERIAL PRIMARY KEY,
    exam_id         INT NOT NULL REFERENCES sportabzeichen_exams(id) ON DELETE CASCADE,
    participant_id  INT NOT NULL REFERENCES sportabzeichen_participants(id) ON DELETE CASCADE,
    age_year        INT NOT NULL,
    total_points    INT DEFAULT 0,
    final_medal     VARCHAR(10) DEFAULT 'NONE',
    UNIQUE (exam_id, participant_id)
);

------------------------------------------------------------
-- 5. Ergebnisse
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_exam_results (
    id              SERIAL PRIMARY KEY,
    ep_id           INT NOT NULL REFERENCES sportabzeichen_exam_participants(id) ON DELETE CASCADE,
    discipline_id   INT NOT NULL REFERENCES sportabzeichen_disciplines(id),
    leistung        DOUBLE PRECISION,
    stufe           TEXT,
    points          INT DEFAULT 0,
    updated_at      TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT uniq_exam_result UNIQUE (ep_id, discipline_id)
);
CREATE INDEX IF NOT EXISTS idx_results_ep ON sportabzeichen_exam_results(ep_id);

------------------------------------------------------------
-- 6. Rechte
------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO symfony;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO symfony;