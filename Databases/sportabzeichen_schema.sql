--
-- Sportabzeichen Modul – Datenbankschema
-- Version 2.6.0 (DOSB + Exam Groups + Training History)
--

------------------------------------------------------------
-- 1. Stammdaten & Teilnehmer
------------------------------------------------------------

-- 1.1 Prüfungen (Exams)
CREATE TABLE IF NOT EXISTS sportabzeichen_exams (
    id              SERIAL PRIMARY KEY,
    exam_name       TEXT,
    exam_date       DATE,
    exam_year       INT NOT NULL,
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW(),
    creator_id      TEXT
);

-- 1.2 Zuordnung Gruppen zu Prüfungen
CREATE TABLE IF NOT EXISTS sportabzeichen_exam_groups (
    exam_id         INT NOT NULL,
    act             VARCHAR(255) NOT NULL, -- Gruppen-Account/ID
    PRIMARY KEY (exam_id, act),
    CONSTRAINT fk_exam_groups_exam_id 
        FOREIGN KEY (exam_id) 
        REFERENCES sportabzeichen_exams (id) 
        ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_exam_groups_act ON sportabzeichen_exam_groups (act);

-- 1.3 Teilnehmer (Participants)
CREATE TABLE IF NOT EXISTS sportabzeichen_participants (
    id              SERIAL PRIMARY KEY,
    geschlecht      TEXT CHECK (geschlecht IN ('MALE','FEMALE')),
    geburtsdatum    DATE,
    updated_at      TIMESTAMPTZ DEFAULT NOW(),
    user_id         INT REFERENCES users(id) ON DELETE SET NULL,
    username        VARCHAR(255)
);
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
-- 4. Prüfungs-Teilnehmer (Verknüpfung Exam <-> Participant)
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
-- 5. Ergebnisse (Results)
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
-- 6. Trainingstagebuch (Historisiert)
------------------------------------------------------------
-- Hier speichern Schüler ihre eigenen Übungswerte (mehrfach pro Jahr möglich)
CREATE TABLE IF NOT EXISTS sportabzeichen_training (
    id              SERIAL PRIMARY KEY,
    user_id         INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    discipline_id   INT NOT NULL REFERENCES sportabzeichen_disciplines(id) ON DELETE CASCADE,
    year            INT NOT NULL,
    value           VARCHAR(255), -- Textfeld für Zeit/Weite (z.B. "12:30" oder "5.20")
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

-- Index für schnellen Zugriff (Alle Einträge eines Users pro Jahr)
CREATE INDEX IF NOT EXISTS idx_training_lookup ON sportabzeichen_training(user_id, year);
-- Index für korrekte Sortierung nach Zeit
CREATE INDEX IF NOT EXISTS idx_training_created ON sportabzeichen_training(created_at);


------------------------------------------------------------
-- 7. Rechte
------------------------------------------------------------
-- Gewährt dem Symfony-User Zugriff auf alle (auch die neuen) Tabellen
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO symfony;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO symfony;