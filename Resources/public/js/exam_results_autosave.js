/**
 * Sportabzeichen Autosave Script (IServ-kompatibel)
 * --------------------------------------------------
 * Speichert Einzelergebnisse (Disziplin + Leistung)
 * automatisch beim Verlassen des Feldes.
 *
 * Funktioniert ohne Inline-JS (CSP-konform).
 * Entwickelt für PulsR SportabzeichenBundle.
 */

document.addEventListener('DOMContentLoaded', function () {
    const fields = document.querySelectorAll('[data-save]');
    if (!fields.length) return;

    console.log('[Sportabzeichen] Autosave initialisiert für', fields.length, 'Felder');

    /**
     * Speichert eine einzelne Änderung per Fetch
     */
    async function saveChange(el) {
        const epId = el.dataset.epId;
        const type = el.dataset.type;
        const disciplineId = el.dataset.disciplineId || (el.tagName === 'SELECT' ? el.value : null);
        const leistung = type === 'leistung' ? el.value : null;

        // Validierung: ohne Teilnehmer-ID oder Disziplin-ID -> Abbruch
        if (!epId || (!disciplineId && type !== 'discipline')) {
            console.warn('[Sportabzeichen] Ungültige Daten:', epId, disciplineId);
            return;
        }

        const payload = {
            ep_id: epId,
            discipline_id: disciplineId,
            leistung: leistung
        };

        // visuelles Feedback vorbereiten
        el.classList.remove('saved', 'error');
        el.classList.add('saving');

        try {
            const res = await fetch(IServ.routes.sportabzeichen_exam_result_save, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': IServ.csrfToken.sportabzeichen_result_save
                },
                body: JSON.stringify(payload)
            });

            if (!res.ok) {
                const text = await res.text();
                throw new Error(text || res.statusText);
            }

            el.classList.remove('saving');
            el.classList.add('saved');

            // gespeicherte Markierung kurz anzeigen
            setTimeout(() => el.classList.remove('saved'), 1200);
        } catch (e) {
            console.error('[Sportabzeichen] Fehler beim Speichern:', e);
            el.classList.remove('saving');
            el.classList.add('error');
        }
    }

    /**
     * Event Listener für alle Felder:
     * - "change": wenn Wert geändert und Feld verlassen
     * - optional auch Dropdown-Änderung
     */
    fields.forEach(el => {
        el.addEventListener('change', function () {
            saveChange(this);
        });

        // Optional: "Enter" erzwingt sofortiges Speichern
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.blur(); // löst "change" aus
            }
        });
    });
});
