document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');

    // --- 1. INITIALISIERUNG: Anforderungen für alle bereits gewählten Disziplinen anzeigen ---
    document.querySelectorAll('.js-discipline-select').forEach(select => {
        updateRequirementHints(select);
    });

    // --- 2. EVENT LISTENER: Ändern & Speichern ---
    form.addEventListener('change', async function(event) {
        const el = event.target;
        
        // Nur reagieren, wenn das Element Teil des Speicher-Prozesses ist
        if (!el.hasAttribute('data-save')) return;

        const epId = el.getAttribute('data-ep-id');
        const kat = el.getAttribute('data-kategorie');
        const cell = el.closest('td');
        const row = el.closest('tr');
        
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');

        // A) Wenn Disziplin gewechselt wurde: Sofort die Anforderungen (B/S/G) aktualisieren
        if (el.tagName === 'SELECT') {
            updateRequirementHints(el);
            // Input leeren bei Disziplinwechsel, damit keine alten Werte stehen bleiben?
            // Optional. Hier lassen wir es, falls User aus Versehen wechselt.
        }

        // Validierung: Ohne Disziplin kann keine Leistung gespeichert werden
        if (!selectEl || !selectEl.value || !epId) return;

        // B) AJAX SAVE REQUEST
        try {
            // UI Feedback: Zeigen, dass gespeichert wird (optional: input leicht ausgrauen)
            inputEl.style.opacity = '0.7';

            const response = await fetch(saveRoute, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    ep_id: epId,
                    discipline_id: selectEl.value,
                    leistung: inputEl.value,
                    _token: csrfToken
                })
            });

            if (!response.ok) throw new Error('Server-Fehler');

            const data = await response.json();
            
            // UI Feedback zurücksetzen
            inputEl.style.opacity = '1';

            if (data.status === 'ok') {
                
                // 1. DIESE ZELLE UPDATE (Farbe der Leistung)
                const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; // 'gold', 'silber', 'bronze', 'none'
                
                [selectEl, inputEl].forEach(element => {
                    element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                    element.classList.add('medal-' + resultColor);
                });

                // 2. ANDERE ZELLEN DER GLEICHEN KATEGORIE BEREINIGEN
                // (Wenn man von 50m Lauf auf 1000m wechselt, muss der 50m Lauf visuell resettet werden)
                if (kat) {
                    const groupInputs = row.querySelectorAll(`[data-kategorie="${kat}"]`);
                    groupInputs.forEach(otherEl => {
                        const otherCell = otherEl.closest('td');
                        if (otherCell !== cell) {
                            if (otherEl.tagName === 'INPUT') otherEl.value = '';
                            if (otherEl.tagName === 'SELECT') {
                                // Optional: Reset Select auf '--' wenn gewünscht
                                // otherEl.value = ''; 
                                // updateRequirementHints(otherEl);
                            }
                            otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                            otherEl.classList.add('medal-none');
                        }
                    });
                }

                // 3. GESAMTPUNKTE UPDATE
                const totalBadge = document.getElementById('total-points-' + epId);
                if (totalBadge) {
                    const valSpan = totalBadge.querySelector('.pts-val');
                    if (valSpan) valSpan.textContent = data.total_points;
                }

                // 4. MEDAILLEN BADGE UPDATE (Gesamtergebnis)
                // Wir suchen das Badge innerhalb der Zeile
                const medalBadge = row.querySelector('.js-medal-badge');
                if (medalBadge) {
                    const medalName = data.final_medal || ''; // 'Gold', 'Silber', 'Bronze' oder null
                    
                    if (medalName) {
                        medalBadge.style.display = 'inline-block';
                        medalBadge.textContent = medalName;
                        // Alte Klassen entfernen
                        medalBadge.className = 'badge badge-mini js-medal-badge';
                        // Neue Klasse hinzufügen
                        if (medalName === 'Gold') medalBadge.classList.add('bg-warning', 'text-dark');
                        else if (medalName === 'Silber') medalBadge.classList.add('bg-secondary', 'text-white');
                        else if (medalName === 'Bronze') medalBadge.classList.add('bg-danger', 'text-white'); // oder style color
                    } else {
                        medalBadge.style.display = 'none';
                        medalBadge.textContent = '';
                    }
                }
            }
        } catch (e) {
            console.error('Fehler beim Autosave:', e);
            inputEl.style.backgroundColor = '#ffe6e6'; // Roter Hint bei Fehler
        }
    });

    // --- HELPER FUNKTIONEN ---

    /**
     * Liest die data-Attribute der gewählten Option aus
     * und schreibt sie in die kleinen B/S/G Labels unter dem Input.
     */
    function updateRequirementHints(select) {
        const parentTd = select.closest('td');
        if (!parentTd) return;

        const selectedOption = select.options[select.selectedIndex];
        
        // Elemente finden
        const labelB = parentTd.querySelector('.js-val-b');
        const labelS = parentTd.querySelector('.js-val-s');
        const labelG = parentTd.querySelector('.js-val-g');
        const unitLabel = parentTd.querySelector('.js-unit-label');
        const input = parentTd.querySelector('input[data-type="leistung"]');

        if (!selectedOption || !selectedOption.value) {
            // Reset wenn nichts gewählt
            if(labelB) labelB.textContent = '-';
            if(labelS) labelS.textContent = '-';
            if(labelG) labelG.textContent = '-';
            if(unitLabel) unitLabel.textContent = '';
            if(input) input.disabled = true;
            return;
        }

        if(input) input.disabled = false;

        // Werte aus data-Attributen der Option holen
        const b = selectedOption.getAttribute('data-bronze') || '-';
        const s = selectedOption.getAttribute('data-silber') || '-';
        const g = selectedOption.getAttribute('data-gold') || '-';
        const unit = selectedOption.getAttribute('data-unit') || '';

        // Text setzen
        if(labelB) labelB.textContent = b;
        if(labelS) labelS.textContent = s;
        if(labelG) labelG.textContent = g;
        if(unitLabel) unitLabel.textContent = unit;
    }
});