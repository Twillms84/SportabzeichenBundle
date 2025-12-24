/**
 * Sportabzeichen Autosave & Scoring System
 */
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');

    /**
     * Kern-Funktion: Sendet Daten an den Server
     */
    async function saveData(epId, disciplineId, leistung, sourceElement) {
        if (!disciplineId) {
            console.warn("Speichern abgebrochen: Keine Disziplin-ID vorhanden.");
            return;
        }

        console.log(`DEBUG: Sende EP=${epId}, Disc=${disciplineId}, Wert=${leistung}`);

        try {
            const response = await fetch(saveRoute, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    ep_id: epId,
                    discipline_id: disciplineId,
                    leistung: leistung,
                    _token: csrfToken
                })
            });

            const data = await response.json();

            if (data.status === 'ok') {
                console.log("DEBUG: Server-Antwort erhalten:", data);
                updateMedalUI(sourceElement, data.medal);
            } else {
                console.error("Server-Fehler:", data.error);
            }
        } catch (error) {
            console.error("Netzwerk-Fehler:", error);
        }
    }

    /**
     * UI-Funktion: Aktualisiert die Farben (Gold, Silber, Bronze)
     */
    function updateMedalUI(element, medal) {
        const cell = element.closest('td');
        const inputs = cell.querySelectorAll('select, input');
        const medalClass = 'medal-' + (medal ? medal.toLowerCase() : 'none');

        console.log(`DEBUG: Setze Farbe ${medalClass} für Zelle.`);

        inputs.forEach(el => {
            // Entferne alle alten Medaillen-Klassen
            el.classList.remove('medal-gold', 'medal-silver', 'medal-bronze', 'medal-none');
            // Füge die neue Klasse hinzu
            el.classList.add(medalClass);
        });
    }

    /**
     * Event-Listener für Änderungen (Dropdown oder Input)
     */
    form.addEventListener('change', function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const epId = el.getAttribute('data-ep-id');
        let disciplineId, leistung, sourceElement = el;

        if (el.getAttribute('data-type') === 'discipline') {
            // FALL A: Disziplin wurde geändert
            disciplineId = el.value;
            const inputId = el.getAttribute('data-target-input');
            const inputEl = document.getElementById(inputId);
            leistung = inputEl.value;
        } else {
            // FALL B: Leistung wurde geändert
            leistung = el.value;
            const selectId = el.getAttribute('data-discipline-select');
            const selectEl = document.getElementById(selectId);
            disciplineId = selectEl.value;
        }

        saveData(epId, disciplineId, leistung, sourceElement);
    });

    // Optional: Enter-Taste im Input-Feld abfangen
    form.addEventListener('keypress', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            event.target.blur(); // Triggert das 'change' Event
        }
    });
});