document.addEventListener('DOMContentLoaded', function() {
    
    // =========================================================
    // 1. ANSICHT FILTER (Select2)
    // =========================================================
    const $viewSelector = $('#viewSelector');

    if ($viewSelector.length) {
        $viewSelector.on('change', function() {
            const selectedCategories = $(this).val() || [];
            
            $('#viewSelector option').each(function() {
                const category = $(this).val();
                const cells = document.querySelectorAll('.col-cat-' + category);
                
                if (selectedCategories.includes(category)) {
                    cells.forEach(cell => cell.classList.remove('col-hidden'));
                } else {
                    cells.forEach(cell => cell.classList.add('col-hidden'));
                }
            });

            localStorage.setItem('sportabzeichen_view_selection', JSON.stringify(selectedCategories));
        });

        const savedSelection = localStorage.getItem('sportabzeichen_view_selection');
        if (savedSelection) {
            try {
                $viewSelector.val(JSON.parse(savedSelection)).trigger('change');
            } catch(e) { console.error(e); }
        }
    }

    // =========================================================
    // 2. AUTOSAVE FORMULAR LOGIK
    // =========================================================
    const form = document.getElementById('autosave-form');
    
    if (!form) return; 

    const disciplineRoute = form.getAttribute('data-discipline-route'); 
    const resultRoute = form.getAttribute('data-result-route');
    const swimmingRoute = form.getAttribute('data-swimming-route'); 
    const swimmingDeleteRoute = form.getAttribute('data-swimming-delete-route');
    const csrfToken = form.getAttribute('data-global-token');

    // --- INITIALISIERUNG BEI SEITENAUFRUF ---
    document.querySelectorAll('.js-discipline-select').forEach(select => {
        updateRequirementHints(select);
        // NEU: Prüfen, ob Verband vorgewählt ist (zum Sperren des Feldes)
        checkVerbandInput(select);
    });

    // --- CHANGE LISTENER (Speichern & UI Logik) ---
    form.addEventListener('change', async function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const epId = el.getAttribute('data-ep-id');
        const type = el.getAttribute('data-type'); // 'discipline', 'leistung', 'swimming_select'
        const kat = el.getAttribute('data-kategorie');
        const cell = el.closest('td');
        const row = el.closest('tr');
        
        let targetRoute = '';
        let payload = {
            ep_id: epId,
            _token: csrfToken
        };

        // A) SCHWIMM-NACHWEIS
        if (type === 'swimming_select') {        
            targetRoute = swimmingRoute;
            payload.discipline_id = el.value;
        } 
        // B) NORMALE DISZIPLINEN
        else {
            const selectEl = cell.querySelector('select');
            const inputEl = cell.querySelector('input[type="text"]');
            
            if (!selectEl || !selectEl.value || !epId) return;

            targetRoute = (el.tagName === 'SELECT') ? disciplineRoute : resultRoute;

            if (el.tagName === 'SELECT') {
                updateRequirementHints(el);
                // NEU: Sofort Verbandsnamen eintragen und sperren
                checkVerbandInput(el); 
            }

            payload.discipline_id = selectEl.value;
            // Wir nehmen den Wert aus dem Input (der jetzt ggf. schon "DLRG" enthält)
            payload.leistung = inputEl ? inputEl.value : '';

            // UI Feedback (kurz ausgrauen)
            if (inputEl && !inputEl.disabled) inputEl.style.opacity = '0.5';
        }

        try {
            const response = await fetch(targetRoute, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) throw new Error('Server-Fehler');
            const data = await response.json();
            
            const inputEl = cell ? cell.querySelector('input[type="text"]') : null;
            if (inputEl) inputEl.style.opacity = '1';

            if (data.status === 'ok' || data.success) {
                // 1. Farben & Inputs (Disziplinen)
                if (type !== 'swimming_select' && cell) {
                    handleDisciplineColors(data, cell, row, kat, el);
                }
                // 2. Globales UI Update (Punkte, Medaille, Schwimmen)
                updateUIWidgets(epId, row, data);
            }
        } catch (e) {
            console.error('Fehler:', e);
            if (el.type === 'text') el.style.backgroundColor = '#ffe6e6';
        }
    });

    // --- CLICK LISTENER (Schwimmen Löschen) ---
    document.addEventListener('click', async function(event) {
        const btn = event.target.closest('.btn-delete-swimming');
        if (!btn) return;

        event.preventDefault();
        const epId = btn.getAttribute('data-ep-id');
        
        if (!swimmingDeleteRoute || !epId) return;

        btn.style.opacity = '0.5';

        try {
            const response = await fetch(swimmingDeleteRoute, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ ep_id: epId, _token: csrfToken })
            });

            const data = await response.json();

            if (data.status === 'ok' || data.success) {
                const row = btn.closest('tr');
                updateUIWidgets(epId, row, data);
            }
        } catch (e) {
            console.error('Fehler beim Löschen:', e);
            alert('Fehler beim Entfernen des Nachweises.');
        } finally {
            btn.style.opacity = '1';
        }
    });

    // =========================================================
    // 3. HELPER FUNCTIONS
    // =========================================================

    // NEU: Zentrale Funktion für Verbands-Logik
    // Setzt den Namen (z.B. "DLRG") und sperrt das Feld, oder gibt es frei.
    // NEU: Präzisere Prüfung
    // NEU: Nur sperren, wenn Einheit = UNIT_NONE
    function checkVerbandInput(selectEl) {
        const cell = selectEl.closest('td');
        const inputEl = cell.querySelector('input[type="text"]');
        if (!inputEl) return;

        const selectedOption = selectEl.options[selectEl.selectedIndex];
        
        // Daten auslesen
        const unit = selectedOption.getAttribute('data-unit');       // z.B. 'UNIT_NONE' oder 'UNIT_PIECES'
        const verbandName = selectedOption.getAttribute('data-verband'); // z.B. 'DLRG' oder '4.2.6.1'

        // LOGIK: Sperren NUR bei UNIT_NONE
        if (unit === 'NONE') {
            // Feld sperren
            inputEl.value = verbandName || 'Nachweis'; // Fallback Text, falls kein Verbandsname da ist
            inputEl.disabled = true;
            inputEl.classList.add('bg-light');
        } else {
            // Alle anderen Einheiten (Minuten, Meter, Stück/Pieces) -> Eingabe erlauben
            inputEl.disabled = false;
            inputEl.classList.remove('bg-light');
            
            // Falls da noch der automatische Text drin steht -> leeren
            if (inputEl.value === verbandName || inputEl.value === 'Nachweis') {
                inputEl.value = '';
            }
        }
    }

    // Farben und Medaillenklassen setzen (Disziplinen)
    function handleDisciplineColors(data, cell, row, kat, el) {
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        const isSelect = (el.tagName === 'SELECT');

        // Klassen aktualisieren (Farben)
        const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
        [selectEl, inputEl].forEach(element => {
            if(element) {
                element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                element.classList.add('medal-' + resultColor);
            }
        });

        // WICHTIG: Nach dem Speichern sicherstellen, dass Verbands-Felder korrekt bleiben
        if (isSelect) {
            checkVerbandInput(selectEl);
        }

        // Konflikte bereinigen (andere Felder der gleichen Kategorie leeren)
        if (isSelect && kat) {
            row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(otherEl => {
                if (otherEl.closest('td') !== cell) {
                    if (otherEl.tagName === 'INPUT') {
                        otherEl.value = '';
                        otherEl.disabled = false; // Andere Felder wieder freigeben
                        otherEl.classList.remove('bg-light');
                    }
                    otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                    otherEl.classList.add('medal-none');
                }
            });
        }
    }

    // UPDATE UI (Medaillen, Punkte, Schwimmen)
    function updateUIWidgets(epId, row, data) {
        
        // A. Gesamtpunkte
        const totalBadge = document.getElementById('total-points-' + epId);
        if (totalBadge && data.total_points !== undefined) {
            totalBadge.textContent = data.total_points;
        }

        // B. Medaille (Gesamt)
        const medalBadge = document.getElementById('final-medal-' + epId);
        if (medalBadge) {
            const medal = data.final_medal ? String(data.final_medal).toLowerCase() : 'none';
            const labelEl = medalBadge.querySelector('.badge-label-small');

            medalBadge.className = 'result-badge-box'; // Reset Classes

            if (medal === 'gold') {
                medalBadge.classList.add('bg-gold');
                if(labelEl) labelEl.textContent = 'Gold';
            } else if (medal === 'silver' || medal === 'silber') {
                medalBadge.classList.add('bg-silver');
                if(labelEl) labelEl.textContent = 'Silber';
            } else if (medal === 'bronze') {
                medalBadge.classList.add('bg-bronze');
                if(labelEl) labelEl.textContent = 'Bronze';
            } else {
                medalBadge.classList.add('medal-none');
                if(labelEl) labelEl.textContent = '-';
            }
        }

        // C. Schwimm-Bereich
        const wrapper = document.getElementById('swimming-wrapper-' + epId);
        if (wrapper) {
            const badgeCont = wrapper.querySelector('.swim-badge-container');
            const dropCont  = wrapper.querySelector('.swim-dropdown-container');
            const infoText  = wrapper.querySelector('.swim-info-text');
            const select    = wrapper.querySelector('select');

            const hasSwimming = (data.has_swimming === true);

            if (hasSwimming) {
                if(badgeCont) badgeCont.classList.remove('d-none');
                if(dropCont)  dropCont.classList.add('d-none');
                if(infoText && data.swimming_met_via) {
                    infoText.textContent = data.swimming_met_via;
                    infoText.title = data.swimming_met_via;
                }
            } else {
                if(badgeCont) badgeCont.classList.add('d-none');
                if(dropCont)  dropCont.classList.remove('d-none');
                if(select)    select.value = ""; 
            }
        }
    }

    // B/S/G Hinweise aktualisieren
    function updateRequirementHints(select) {
        const parentTd = select.closest('td');
        const opt = select.options[select.selectedIndex];
        if (!parentTd || !opt) return;

        const labels = {
            b: parentTd.querySelector('.req-val-b'),
            s: parentTd.querySelector('.req-val-s'),
            g: parentTd.querySelector('.req-val-g'),
            unit: parentTd.querySelector('.req-unit')
        };
        // Fallback für alte Klassen
        if (!labels.b) labels.b = parentTd.querySelector('.js-val-b');
        if (!labels.s) labels.s = parentTd.querySelector('.js-val-s');
        if (!labels.g) labels.g = parentTd.querySelector('.js-val-g');
        if (!labels.unit) labels.unit = parentTd.querySelector('.input-group-text-unit');

        const input = parentTd.querySelector('input[data-type="leistung"]');

        if (!opt.value) {
            Object.values(labels).forEach(l => l && (l.textContent = (l === labels.unit ? '' : '-')));
            if(input) input.disabled = true;
            return;
        }

        // Hier nur Hinweise updaten. Disable/Enable übernimmt jetzt checkVerbandInput!
        
        if(labels.b) labels.b.textContent = opt.getAttribute('data-bronze') || '-';
        if(labels.s) labels.s.textContent = opt.getAttribute('data-silber') || '-';
        if(labels.g) labels.g.textContent = opt.getAttribute('data-gold') || '-';
        
        if(labels.unit) {
            labels.unit.textContent = opt.getAttribute('data-unit') || '';
        }
    }
});