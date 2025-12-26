document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');
    const examId = form.getAttribute('data-exam-id');

    async function handleSave(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const cell = el.closest('td');
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        const epId = el.getAttribute('data-participant');

        if (!epId) return;

        // Visuelles Feedback: "Speichert..."
        inputEl.style.opacity = "0.5";

        const formData = new FormData();
        formData.append('ep_id', epId);
        formData.append('exam_id', examId);
        formData.append('discipline_id', selectEl.value);
        formData.append('leistung', inputEl.value.replace(',', '.'));
        formData.append('_token', csrfToken);

        try {
            const response = await fetch(saveRoute, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });

            const result = await response.json();

            if (response.ok && (result.status === 'ok' || result.success)) {
                // UI Update
                updateMedalUI(cell, result.medal);
                
                const totalBadge = document.getElementById(`total-points-${epId}`);
                if (totalBadge && result.totalPoints !== undefined) {
                    totalBadge.textContent = `${result.totalPoints} Pkt.`;
                }

                // Erfolg: Kurz grün leuchten
                inputEl.style.outline = "2px solid green";
                setTimeout(() => inputEl.style.outline = "none", 1000);
            } else {
                throw new Error(result.message || 'Speichern fehlgeschlagen');
            }
        } catch (error) {
            console.error("Autosave Error Details:", error);
            inputEl.style.outline = "2px solid red";
        } finally {
            inputEl.style.opacity = "1";
        }
    }

    function updateMedalUI(cell, medal) {
        const medalClass = (medal && medal !== 'none') ? 'medal-' + medal.toLowerCase() : 'medal-none';
        cell.querySelectorAll('select, input').forEach(el => {
            el.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
            el.classList.add(medalClass);
        });
    }

    // Listener registrieren
    form.addEventListener('change', handleSave);
});

    /**
     * 2. Hilfsfunktion: Gesamtpunkte in der Zeile aktualisieren
     */
    function updateRowTotal(epId, newTotal) {
        const badge = document.getElementById(`total-points-${epId}`);
        if (badge && newTotal !== undefined) {
            badge.textContent = `${newTotal} Pkt.`;
        }
    }

    /**
     * EVENT LISTENER für Änderungen
     */
    form.addEventListener('change', async function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const cell = el.closest('td');
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        
        // WICHTIG: Hier muss das Attribut stehen, das du im Twig nutzt!
        // Da du im Twig data-participant="{{ p.ep_id }}" nutzt:
        const epId = el.getAttribute('data-participant'); 

        if (!epId) {
            console.error("Keine Participant-ID (ep_id) am Element gefunden!");
            return;
        }

        // Falls Disziplin geleert wurde
        if (!selectEl.value) {
            updateMedalUI(cell, 'none');
            // Hier müsste man ggf. dem Server sagen, dass der Wert gelöscht wurde
        }

        // Wir nutzen URLSearchParams für einen klassischen POST-Request (vermeidet 400er)
        const formData = new URLSearchParams();
        formData.append('ep_id', epId);
        formData.append('exam_id', examId || ''); 
        formData.append('discipline_id', selectEl.value);
        formData.append('leistung', inputEl.value.replace(',', '.'));
        formData.append('_token', csrfToken);

        try {
            const response = await fetch(saveRoute, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest' 
                },
                body: formData
            });

            if (!response.ok) {
                throw new Error(`Server-Fehler: ${response.status}`);
            }

            const data = await response.json();
            
            if (data.status === 'ok' || data.success) {
                // UI Farben aktualisieren
                updateMedalUI(cell, data.medal);
                
                // Punkte-Badge aktualisieren (falls der Server totalPoints mitschickt)
                if (data.totalPoints !== undefined) {
                    updateRowTotal(epId, data.totalPoints);
                }
                
                // Kleiner visueller Erfolgshinweis (optional)
                inputEl.style.outline = "2px solid rgba(40, 167, 69, 0.5)";
                setTimeout(() => inputEl.style.outline = "none", 1000);
            } else {
                console.error("Speichern fehlgeschlagen:", data.message);
                inputEl.style.outline = "2px solid rgba(220, 53, 69, 0.5)";
            }
        } catch (e) { 
            console.error("Netzwerkfehler beim Autosave:", e);
            alert("Fehler beim automatischen Speichern. Bitte Verbindung prüfen.");
        }
    });

    // Fix für Dropdown-Farben: Wenn das Feld fokusiert wird, Farbe kurz neutralisieren
    // damit das System-Dropdown (weiß) nicht mit der Medaillenfarbe kollidiert.
    form.querySelectorAll('select.discipline-selector').forEach(select => {
        select.addEventListener('focus', function() {
            this.dataset.oldClass = this.className;
            this.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
        });
        select.addEventListener('blur', function() {
            if (this.dataset.oldClass) {
                // Die Farbe wird beim Blur (oder nach dem Change durch updateMedalUI) wiederhergestellt
            }
        });
    });