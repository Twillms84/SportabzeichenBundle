document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('autosave-form');
    if (!form) return;

    const saveRoute = form.getAttribute('data-global-route');
    const csrfToken = form.getAttribute('data-global-token');

    // --- 1. INITIALISIERUNG: Anforderungen anzeigen ---
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
        }

        // Validierung
        if (!selectEl || !selectEl.value || !epId) return;

        // B) AJAX SAVE REQUEST
        try {
            // UI Feedback: Zeigen, dass gespeichert wird
            inputEl.style.opacity = '0.5';

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
                
                // 1. ZELLEN-FARBE UPDATE (Einzeldisziplin)
                // Controller sendet jetzt 'gold', 'silber', 'bronze' oder 'none' (alles klein)
                const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
                
                [selectEl, inputEl].forEach(element => {
                    element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                    element.classList.add('medal-' + resultColor);
                });

                // 2. ANDERE ZELLEN DER GLEICHEN KATEGORIE BEREINIGEN
                if (kat) {
                    const groupInputs = row.querySelectorAll(`[data-kategorie="${kat}"]`);
                    groupInputs.forEach(otherEl => {
                        const otherCell = otherEl.closest('td');
                        // Nur andere Zellen bearbeiten, nicht die aktuelle
                        if (otherCell !== cell) {
                            if (otherEl.tagName === 'INPUT') otherEl.value = '';
                            if (otherEl.tagName === 'SELECT') {
                                // Optional: Reset Select, falls gewünscht
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

                // 4. MEDAILLEN BADGE UPDATE (Gesamtergebnis) -> HIER WAR DIE WICHTIGSTE ÄNDERUNG
                const medalBadge = row.querySelector('.js-medal-badge');
                if (medalBadge) {
                    // Sicherstellen, dass wir einen String haben
                    const rawMedalName = data.final_medal ? String(data.final_medal) : ''; 
                    const lowerMedalName = rawMedalName.toLowerCase();

                    if (lowerMedalName && lowerMedalName !== 'none') {
                        medalBadge.style.display = 'inline-block';
                        
                        // Text kapitalisieren (gold -> Gold) für die Anzeige
                        medalBadge.textContent = rawMedalName.charAt(0).toUpperCase() + rawMedalName.slice(1);
                        
                        // Alte Klassen entfernen
                        medalBadge.className = 'badge badge-mini js-medal-badge'; // Basisklassen resetten
                        
                        // Neue Klasse hinzufügen (basierend auf Kleinschreibung prüfen)
                        if (lowerMedalName === 'gold') {
                            medalBadge.classList.add('bg-warning', 'text-dark');
                        } else if (lowerMedalName === 'silber') {
                            medalBadge.classList.add('bg-secondary', 'text-white');
                        } else if (lowerMedalName === 'bronze') {
                            // Bei Bronze nehmen wir oft 'danger' (rot) oder 'warning' mit dunklerer Schrift, 
                            // oder einen eigenen Style. Standard Bootstrap 'bg-danger' passt oft gut genug als Bronze-Ersatz.
                            medalBadge.classList.add('bg-danger', 'text-white'); 
                        }
                    } else {
                        medalBadge.style.display = 'none';
                        medalBadge.textContent = '';
                    }
                }
            }
        } catch (e) {
            console.error('Fehler beim Autosave:', e);
            inputEl.style.backgroundColor = '#ffe6e6'; // Fehler rot markieren
        }
    });

    // --- HELPER FUNKTIONEN ---

    function updateRequirementHints(select) {
        const parentTd = select.closest('td');
        if (!parentTd) return;

        const selectedOption = select.options[select.selectedIndex];
        
        const labelB = parentTd.querySelector('.js-val-b');
        const labelS = parentTd.querySelector('.js-val-s');
        const labelG = parentTd.querySelector('.js-val-g');
        const unitLabel = parentTd.querySelector('.js-unit-label');
        const input = parentTd.querySelector('input[data-type="leistung"]');

        if (!selectedOption || !selectedOption.value) {
            if(labelB) labelB.textContent = '-';
            if(labelS) labelS.textContent = '-';
            if(labelG) labelG.textContent = '-';
            if(unitLabel) unitLabel.textContent = '';
            if(input) input.disabled = true;
            return;
        }

        if(input) input.disabled = false;

        // Diese Attribute müssen im Twig Template gesetzt werden!
        const b = selectedOption.getAttribute('data-bronze') || '-';
        const s = selectedOption.getAttribute('data-silber') || '-';
        const g = selectedOption.getAttribute('data-gold') || '-';
        const unit = selectedOption.getAttribute('data-unit') || '';

        if(labelB) labelB.textContent = b;
        if(labelS) labelS.textContent = s;
        if(labelG) labelG.textContent = g;
        if(unitLabel) unitLabel.textContent = unit;
    }
});