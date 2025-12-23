--
-- Sportabzeichen Modul – Datenbankschema
-- Version 2.2.0 (mit Auto-Scoring Trigger)
--

------------------------------------------------------------
-- 1. Prüfungen & Teilnehmer (Unverändert)
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
    geschlecht      TEXT CHECK (geschlecht IN ('MALE','FEMALE')),
    geburtsdatum    DATE,
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

------------------------------------------------------------
-- 3. Disziplinen
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_disciplines (
    id              SERIAL PRIMARY KEY,
    name            TEXT NOT NULL,
    kategorie       TEXT NOT NULL,
    einheit         TEXT NOT NULL,
    berechnungsart  TEXT NOT NULL DEFAULT 'GREATER', -- 'GREATER' oder 'SMALLER'
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT uniq_sportabzeichen_disciplines_name UNIQUE (name)
);

------------------------------------------------------------
-- 4. Anforderungen
------------------------------------------------------------
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
    schwimmnachweis BOOLEAN DEFAULT FALSE
);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_sportabzeichen_requirements ON sportabzeichen_requirements (discipline_id, jahr, age_min, age_max, geschlecht);
CREATE INDEX IF NOT EXISTS idx_req_lookup ON sportabzeichen_requirements (jahr, geschlecht, age_min, age_max);

------------------------------------------------------------
-- 5. Prüfungs-Teilnehmer
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_exam_participants (
    id              SERIAL PRIMARY KEY,
    exam_id         INT NOT NULL REFERENCES sportabzeichen_exams(id) ON DELETE CASCADE,
    participant_id  INT NOT NULL REFERENCES sportabzeichen_participants(id) ON DELETE CASCADE,
    age_year        INT NOT NULL,
    UNIQUE (exam_id, participant_id)
);

------------------------------------------------------------
-- 6. Ergebnisse (Erweitert um 'points')
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_exam_results (
    id              SERIAL PRIMARY KEY,
    ep_id           INT NOT NULL REFERENCES sportabzeichen_exam_participants(id) ON DELETE CASCADE,
    discipline_id   INT NOT NULL REFERENCES sportabzeichen_disciplines(id),
    leistung        DOUBLE PRECISION,
    stufe           TEXT,   -- Speichert 'GOLD', 'SILBER', 'BRONZE'
    points          INT DEFAULT 0, -- Speichert 1, 2, 3
    updated_at      TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT uniq_exam_result UNIQUE (ep_id, discipline_id)
);

------------------------------------------------------------
-- 7. AUTOMATISIERUNG: Scoring-Trigger
------------------------------------------------------------

-- Funktion zur automatischen Punkteberechnung
CREATE OR REPLACE FUNCTION fn_calculate_sportabzeichen_points()
RETURNS TRIGGER AS $$
DECLARE
    req_record RECORD;
    calc_type TEXT;
BEGIN
    -- 1. Berechnungsart der Disziplin holen
    SELECT berechnungsart INTO calc_type FROM sportabzeichen_disciplines WHERE id = NEW.discipline_id;

    -- 2. Passende Anforderung finden (Jahr der Prüfung, Geschlecht, Alter)
    SELECT r.* INTO req_record
    FROM sportabzeichen_requirements r
    JOIN sportabzeichen_exam_participants ep ON ep.id = NEW.ep_id
    JOIN sportabzeichen_participants p ON p.id = ep.participant_id
    JOIN sportabzeichen_exams ex ON ex.id = ep.exam_id
    WHERE r.discipline_id = NEW.discipline_id
      AND r.jahr = ex.exam_year
      AND r.geschlecht = p.geschlecht
      AND ep.age_year BETWEEN r.age_min AND r.age_max
    LIMIT 1;

    -- 3. Punkte vergeben
    IF req_record IS NOT NULL AND NEW.leistung IS NOT NULL THEN
        IF calc_type = 'GREATER' THEN
            -- Weite/Höhe: Mehr ist besser
            IF NEW.leistung >= req_record.gold THEN NEW.points := 3; NEW.stufe := 'GOLD';
            ELSIF NEW.leistung >= req_record.silber THEN NEW.points := 2; NEW.stufe := 'SILBER';
            ELSIF NEW.leistung >= req_record.bronze THEN NEW.points := 1; NEW.stufe := 'BRONZE';
            ELSE NEW.points := 0; NEW.stufe := 'NONE'; END IF;
        ELSE
            -- Zeit (SMALLER): Weniger ist besser
            IF NEW.leistung <= req_record.gold AND req_record.gold > 0 THEN NEW.points := 3; NEW.stufe := 'GOLD';
            ELSIF NEW.leistung <= req_record.silber AND req_record.silber > 0 THEN NEW.points := 2; NEW.stufe := 'SILBER';
            ELSIF NEW.leistung <= req_record.bronze AND req_record.bronze > 0 THEN NEW.points := 1; NEW.stufe := 'BRONZE';
            ELSE NEW.points := 0; NEW.stufe := 'NONE'; END IF;
        END IF;
    ELSE
        NEW.points := 0;
        NEW.stufe := 'NONE';
    END IF;

    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger aktivieren
DROP TRIGGER IF EXISTS trg_calculate_points ON sportabzeichen_exam_results;
CREATE TRIGGER trg_calculate_points
BEFORE INSERT OR UPDATE OF leistung ON sportabzeichen_exam_results
FOR EACH ROW EXECUTE FUNCTION fn_calculate_sportabzeichen_points();

------------------------------------------------------------
-- 8. Rechte (Unverändert)
------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE, DELETE ON 
    sportabzeichen_exams, sportabzeichen_participants, sportabzeichen_disciplines, 
    sportabzeichen_requirements, sportabzeichen_exam_participants, sportabzeichen_exam_results 
TO symfony;

GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO symfony;