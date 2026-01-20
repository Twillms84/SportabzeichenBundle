document.addEventListener('DOMContentLoaded', function() {
    
    // =========================================================
    // 1. ANSICHT FILTER (Select2)
    // =========================================================
    
    // Wir nutzen jQuery, da Select2 darauf basiert
    const $viewSelector = $('#viewSelector');

    if ($viewSelector.length) {
        
        // Event Listener für Änderungen
        $viewSelector.on('change', function() {
            // Hole alle ausgewählten Kategorien als Array
            // Wenn leer (alles abgewählt), ist val() null -> daher '|| []'
            const selectedCategories = $(this).val() || [];
            
            // Wir iterieren über ALLE Optionen in der Liste (nicht nur die ausgewählten)
            // um zu entscheiden, was wir zeigen und was wir verstecken.
            $('#viewSelector option').each(function() {
                const category = $(this).val();
                
                // Native JS Selektor für Performance
                const cells = document.querySelectorAll('.col-cat-' + category);
                
                if (selectedCategories.includes(category)) {
                    // Kategorie ist ausgewählt -> ANZEIGEN
                    cells.forEach(cell => cell.classList.remove('col-hidden'));
                } else {
                    // Kategorie ist NICHT ausgewählt -> VERSTECKEN
                    cells.forEach(cell => cell.classList.add('col-hidden'));
                }
            });

            // Optional: Speichern in LocalStorage, damit es beim Reload so bleibt
            localStorage.setItem('sportabzeichen_view_selection', JSON.stringify(selectedCategories));
        });

        // Beim Laden der Seite: Gespeicherten Zustand wiederherstellen?
        const savedSelection = localStorage.getItem('sportabzeichen_view_selection');
        if (savedSelection) {
            try {
                // Setze die Werte im Select2 und feuere das Change-Event
                $viewSelector.val(JSON.parse(savedSelection)).trigger('change');
            } catch(e) { console.error(e); }
        }
    }

        // B) Change Listener (feuert bei Auswahl & Abwahl)
        $colSelector.on('change', function() {
            // Hole Array der ausgewählten Werte (z.B. ['Ausdauer', 'Kraft'])
            // Fallback auf leeres Array, falls alles abgewählt ist
            const selectedCategories = $(this).val() || [];

            // Speichern für den nächsten Besuch
            localStorage.setItem('sportabzeichen_view_cols', JSON.stringify(selectedCategories));

            // UI Update: Wir gehen ALLE verfügbaren Optionen durch
            $('#columnSelector option').each(function() {
                const category = $(this).val();
                
                // Alle Tabellenzellen (th und td) dieser Kategorie suchen
                // Wir nutzen hier natives JS für Performance
                const cells = document.querySelectorAll('.col-cat-' + category);
                
                if (selectedCategories.includes(category)) {
                    // Ist ausgewählt -> Anzeigen
                    cells.forEach(cell => cell.classList.remove('col-hidden'));
                } else {
                    // Ist NICHT ausgewählt -> Ausblenden
                    cells.forEach(cell => cell.classList.add('col-hidden'));
                }
            });
        });
        
        // Initial einmal ausführen, damit die Tabelle zum Start stimmt
        // (falls LocalStorage leer war, nimmt er die Standard-Auswahl aus dem HTML)
        $colSelector.trigger('change');
    }


    // =========================================================
    // 2. AUTOSAVE FORMULAR LOGIK (Unverändert)
    // =========================================================
    const form = document.getElementById('autosave-form');
    // Abbrechen, wenn Formular nicht existiert (z.B. auf anderen Seiten)
    if (!form) return; 

    // Routen aus dem Formular-Tag lesen
    const disciplineRoute = form.getAttribute('data-discipline-route'); 
    const resultRoute = form.getAttribute('data-result-route');
    const swimmingRoute = form.getAttribute('data-swimming-route'); 
    const swimmingDeleteRoute = form.getAttribute('data-swimming-delete-route');
    const csrfToken = form.getAttribute('data-global-token');

    // Initiale Hinweise (Bronze/Silber/Gold Werte) setzen
    document.querySelectorAll('.js-discipline-select').forEach(select => {
        updateRequirementHints(select);
    });

    // --- CHANGE LISTENER (Speichern) ---
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
        // B) NORMALE DISZIPLINEN / LEISTUNGEN
        else {
            const selectEl = cell.querySelector('select');
            const inputEl = cell.querySelector('input[type="text"]');
            
            if (!selectEl || !selectEl.value || !epId) return;

            // Route bestimmen
            targetRoute = (el.tagName === 'SELECT') ? disciplineRoute : resultRoute;

            if (el.tagName === 'SELECT') {
                updateRequirementHints(el);
            }

            payload.discipline_id = selectEl.value;
            payload.leistung = inputEl ? inputEl.value : '';

            // UI Feedback (kurz ausgrauen)
            if (inputEl) inputEl.style.opacity = '0.5';
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
            
            // UI Feedback zurücksetzen
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
            if (el.type === 'text') el.style.backgroundColor = '#ffe6e6'; // Rot bei Fehler
        }
    });

    // --- CLICK LISTENER (Schwimmen Löschen) ---
    document.addEventListener('click', async function(event) {
        const btn = event.target.closest('.js-delete-swimming');
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

    /**
     * Setzt Farben (Gold/Silber/Bronze) an Select und Input
     */
    function handleDisciplineColors(data, cell, row, kat, el) {
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        const isSelect = (el.tagName === 'SELECT');

        // Logik für "Verbands"-Leistungen (automatisches Ausfüllen verhindern/setzen)
        if (data.points === 3 && data.stufe === 'gold' && isSelect) {
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            if (selectedOption && selectedOption.getAttribute('data-calc') === 'VERBAND') {
                if(inputEl) {
                    inputEl.value = ''; 
                    inputEl.disabled = true;
                    inputEl.placeholder = 'Verband';
                }
            }
        } else if (isSelect && inputEl) {
            inputEl.disabled = false;
            inputEl.placeholder = '';
        }

        // Klassen aktualisieren
        const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
        [selectEl, inputEl].forEach(element => {
            if(element) {
                element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                element.classList.add('medal-' + resultColor);
            }
        });

        // Konfliktlösung: Andere Felder der gleichen Kategorie zurücksetzen
        if (isSelect && kat) {
            row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(otherEl => {
                if (otherEl.closest('td') !== cell) {
                    if (otherEl.tagName === 'INPUT') otherEl.value = '';
                    otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                    otherEl.classList.add('medal-none');
                }
            });
        }
    }

    /**
     * Aktualisiert die Gesamtpunkte, Medaille und Schwimm-Status
     */
    function updateUIWidgets(epId, row, data) {
        // A. Gesamtpunkte
        const totalBadge = document.getElementById('total-points-' + epId);
        if (totalBadge && data.total_points !== undefined) {
            totalBadge.textContent = data.total_points;
        }

        // B. Medaille
        const medalBadge = document.getElementById('final-medal-' + epId);
        if (medalBadge) {
            const medal = data.final_medal ? String(data.final_medal).toLowerCase() : 'none';
            const labelEl = medalBadge.querySelector('.medal-label');

            medalBadge.className = 'result-badge-box rounded px-2 py-1 text-center js-medal-badge';

            if (medal === 'gold') {
                medalBadge.classList.add('bg-gold', 'text-dark', 'border-gold');
                if(labelEl) labelEl.textContent = 'Gold';
            } else if (medal === 'silver' || medal === 'silber') {
                medalBadge.classList.add('bg-silver', 'text-dark', 'border-silver');
                if(labelEl) labelEl.textContent = 'Silber';
            } else if (medal === 'bronze') {
                medalBadge.classList.add('bg-bronze', 'text-white', 'border-bronze');
                if(labelEl) labelEl.textContent = 'Bronze';
            } else {
                medalBadge.classList.add('bg-light', 'text-muted', 'border');
                if(labelEl) labelEl.textContent = '-';
            }
        }

        // C. Schwimm-Bereich
        const wrapper = document.getElementById('swimming-wrapper-' + epId);
        if (wrapper) {
            const badgeCont = wrapper.querySelector('.swim-badge-container');
            const dropCont  = wrapper.querySelector('.swim-dropdown-container');
            const infoText  = wrapper.querySelector('.js-swim-info');
            const select    = wrapper.querySelector('select');

            const hasSwimming = (data.has_swimming === true);

            if (hasSwimming) {
                if(badgeCont) badgeCont.classList.remove('d-none');
                if(dropCont)  dropCont.classList.add('d-none');
                
                if(infoText && data.swimming_met_via) {
                    infoText.textContent = data.swimming_met_via;
                }
            } else {
                if(badgeCont) badgeCont.classList.add('d-none');
                if(dropCont)  dropCont.classList.remove('d-none');
                if(select)    select.value = ""; 
            }
        }
    }

    /**
     * Updated die kleinen B/S/G Hinweise unter dem Input
     */
    function updateRequirementHints(select) {
        const parentTd = select.closest('td');
        const opt = select.options[select.selectedIndex];
        if (!parentTd || !opt) return;

        const labels = {
            b: parentTd.querySelector('.js-val-b'),
            s: parentTd.querySelector('.js-val-s'),
            g: parentTd.querySelector('.js-val-g'),
            unit: parentTd.querySelector('.input-group-text-unit')
        };
        if (!labels.unit) labels.unit = parentTd.querySelector('.js-unit-label');

        const input = parentTd.querySelector('input[data-type="leistung"]');

        if (!opt.value) {
            Object.values(labels).forEach(l => l && (l.textContent = (l === labels.unit ? '' : '-')));
            if(input) input.disabled = true;
            return;
        }

        if(input) input.disabled = (opt.getAttribute('data-calc') === 'VERBAND');
        
        if(labels.b) labels.b.textContent = opt.getAttribute('data-bronze') || '-';
        if(labels.s) labels.s.textContent = opt.getAttribute('data-silber') || '-';
        if(labels.g) labels.g.textContent = opt.getAttribute('data-gold') || '-';
        
        if(labels.unit) {
            labels.unit.textContent = opt.getAttribute('data-unit') || '';
        }
    }
});