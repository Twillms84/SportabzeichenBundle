/**
 * Sportabzeichen Autosave & Scoring System (Combined)
 */
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');

    /**
     * UI-Funktion: Aktualisiert die Farben (Gold, Silber, Bronze) in der gesamten Zelle
     */
    function updateMedalUI(sourceElement, medal) {
        // Wir suchen die umschließende Zelle (td), um alle darin enthaltenen Felder zu färben
        const cell = sourceElement.closest('td');
        if (!cell) return;

        const medalClass = 'medal-' + (medal ? medal.toLowerCase() : 'none');
        const inputs = cell.querySelectorAll('select, input');

        console.log(`[UI] Setze Klasse: ${medalClass}`);

        inputs.forEach(el => {
            // WICHTIG: Erst alle alten Medaillen-Klassen entfernen, damit die neue greift
            el.classList.remove('medal-gold', 'medal-silver', 'medal-bronze', 'medal-none');
            el.classList.add(medalClass);
        });
    }

    /**
     * Kern-Funktion: Sendet Daten per AJAX an den Server
     */
    async function saveData(epId, disciplineId, leistung, sourceElement) {
        // Falls keine Disziplin gewählt ist, UI zurücksetzen und abbrechen
        if (!disciplineId || disciplineId === "") {
            updateMedalUI(sourceElement, 'none');
            return;
        }

        console.log(`[Ajax] Sende EP=${epId}, Disc=${disciplineId}, Wert=${leistung}`);

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
                    leistung: leistung.toString().replace(',', '.'), // Komma-Korrektur für DB
                    _token: csrfToken
                })
            });

            const data = await response.json();

            if (data.status === 'ok') {
                console.log("[Ajax] Antwort vom Server:", data);
                // WICHTIG: Wir nutzen die 'medal' aus der Antwort, die der DB-Trigger berechnet hat
                updateMedalUI(sourceElement, data.medal);
            } else {
                console.error("[Ajax] Fehler:", data.error);
            }
        } catch (error) {
            console.error("[Ajax] Netzwerk-Fehler:", error);
        }
    }

    /**
     * Event-Listener: Reagiert auf jede Änderung im Formular
     */
    form.addEventListener('change', function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const epId = el.getAttribute('data-ep-id');
        const cell = el.closest('td');
        
        // Wir suchen die Elemente IMMER live in der Zelle, um keine alten IDs zu nutzen
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');

        const disciplineId = selectEl.value;
        const leistung = inputEl.value;

        // Speichern auslösen
        saveData(epId, disciplineId, leistung, el);
    });

    /**
     * Enter-Taste abfangen, damit sie wie ein Verlassen des Feldes (blur) wirkt
     */
    form.addEventListener('keypress', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            event.target.blur(); 
        }
    });
});