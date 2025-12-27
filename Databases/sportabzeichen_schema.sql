--
-- Sportabzeichen Modul – Datenbankschema
-- Version 2.3.0 (DOSB Swimming & Live-Aggregation)
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
-- 2. DOSB Schwimmnachweis (Langfristig gültig)
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_swimming_proofs (
    id                  SERIAL PRIMARY KEY,
    participant_id      INT NOT NULL REFERENCES sportabzeichen_participants(id) ON DELETE CASCADE,
    confirmed_at        DATE NOT NULL,
    valid_until         DATE NOT NULL, -- DOSB: +5 Jahre oder Ende Jugendzeit
    requirement_met_via TEXT,          -- 'DISCIPLINE' oder 'CERTIFICATE'
    confirmed_by_user   TEXT,          -- IServ Username
    created_at          TIMESTAMPTZ DEFAULT NOW()
);

------------------------------------------------------------
-- 3. Disziplinen & Anforderungen
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_disciplines (
    id              SERIAL PRIMARY KEY,
    name            TEXT NOT NULL,
    kategorie       TEXT NOT NULL, -- 'AUSDAUER', 'KRAFT', 'SCHNELLIGKEIT', 'KOORDINATION', 'SWIMMING'
    einheit         TEXT NOT NULL,
    berechnungsart  TEXT NOT NULL DEFAULT 'GREATER',
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    CONSTRAINT uniq_sportabzeichen_disciplines_name UNIQUE (name)
);

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

------------------------------------------------------------
-- 4. Prüfungs-Teilnehmer (Erweitert um Cache-Felder)
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sportabzeichen_exam_participants (
    id              SERIAL PRIMARY KEY,
    exam_id         INT NOT NULL REFERENCES sportabzeichen_exams(id) ON DELETE CASCADE,
    participant_id  INT NOT NULL REFERENCES sportabzeichen_participants(id) ON DELETE CASCADE,
    age_year        INT NOT NULL,
    total_points    INT DEFAULT 0,       -- Summe der 4 Kategorien
    final_medal     TEXT DEFAULT 'NONE', -- 'GOLD', 'SILBER', 'BRONZE', 'NONE'
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

------------------------------------------------------------
-- 6. AUTOMATISIERUNG: Trigger
------------------------------------------------------------

-- TRIGGER 1: Punkte pro Disziplin berechnen
CREATE OR REPLACE FUNCTION fn_calculate_sportabzeichen_points()
RETURNS TRIGGER AS $$
DECLARE
    req_record RECORD;
    calc_type TEXT;
    is_swimming BOOLEAN;
BEGIN
    SELECT berechnungsart, (kategorie = 'SWIMMING' OR kategorie = 'SCHWIMMEN') 
    INTO calc_type, is_swimming 
    FROM sportabzeichen_disciplines WHERE id = NEW.discipline_id;

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

    IF req_record IS NOT NULL AND NEW.leistung IS NOT NULL THEN
        IF calc_type = 'GREATER' THEN
            IF NEW.leistung >= req_record.gold THEN NEW.points := 3; NEW.stufe := 'GOLD';
            ELSIF NEW.leistung >= req_record.silber THEN NEW.points := 2; NEW.stufe := 'SILBER';
            ELSIF NEW.leistung >= req_record.bronze THEN NEW.points := 1; NEW.stufe := 'BRONZE';
            ELSE NEW.points := 0; NEW.stufe := 'NONE'; END IF;
        ELSE
            IF NEW.leistung <= req_record.gold AND req_record.gold > 0 THEN NEW.points := 3; NEW.stufe := 'GOLD';
            ELSIF NEW.leistung <= req_record.silber AND req_record.silber > 0 THEN NEW.points := 2; NEW.stufe := 'SILBER';
            ELSIF NEW.leistung <= req_record.bronze AND req_record.bronze > 0 THEN NEW.points := 1; NEW.stufe := 'BRONZE';
            ELSE NEW.points := 0; NEW.stufe := 'NONE'; END IF;
        END IF;

        -- SONDERREGEL: Schwimmen gibt 0 Punkte für die Gesamtwertung
        IF is_swimming THEN
            NEW.points := 0;
        END IF;
    ELSE
        NEW.points := 0;
        NEW.stufe := 'NONE';
    END IF;

    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- TRIGGER 2: Gesamtpunkte in sportabzeichen_exam_participants aktualisieren
CREATE OR REPLACE FUNCTION fn_aggregate_total_scores()
RETURNS TRIGGER AS $$
DECLARE
    v_total_points INT;
    v_final_medal TEXT;
BEGIN
    -- Summe der Punkte berechnen (Schwimmen hat 0 Punkte via Trigger 1)
    SELECT COALESCE(SUM(points), 0) INTO v_total_points 
    FROM sportabzeichen_exam_results 
    WHERE ep_id = NEW.ep_id;

    -- Finale Medaille bestimmen
    IF v_total_points >= 11 THEN v_final_medal := 'GOLD';
    ELSIF v_total_points >= 8 THEN v_final_medal := 'SILBER';
    ELSIF v_total_points >= 4 THEN v_final_medal := 'BRONZE';
    ELSE v_final_medal := 'NONE'; END IF;

    UPDATE sportabzeichen_exam_participants 
    SET total_points = v_total_points, 
        final_medal = v_final_medal
    WHERE id = NEW.ep_id;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger aktivieren
DROP TRIGGER IF EXISTS trg_calculate_points ON sportabzeichen_exam_results;
CREATE TRIGGER trg_calculate_points
BEFORE INSERT OR UPDATE OF leistung, discipline_id ON sportabzeichen_exam_results
FOR EACH ROW EXECUTE FUNCTION fn_calculate_sportabzeichen_points();

DROP TRIGGER IF EXISTS trg_after_result_update ON sportabzeichen_exam_results;
CREATE TRIGGER trg_after_result_update
AFTER INSERT OR UPDATE OF points ON sportabzeichen_exam_results
FOR EACH ROW EXECUTE FUNCTION fn_aggregate_total_scores();

------------------------------------------------------------
-- 7. Rechte
------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO symfony;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO symfony;