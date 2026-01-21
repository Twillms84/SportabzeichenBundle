document.addEventListener('DOMContentLoaded', function() {
    
    // =========================================================
    // 1. ANSICHT FILTER (Select2 / Category Logic)
    // =========================================================
    const $viewSelector = $('#viewSelector'); // jQuery Annahme, sonst document.querySelector

    if ($viewSelector.length) {
        $viewSelector.on('change', function() {
            const selectedCategories = $(this).val() || [];
            
            // Iteriere über alle Optionen, um Spalten ein- oder auszublenden
            $('#viewSelector option').each(function() {
                const category = $(this).val();
                const cells = document.querySelectorAll('.col-cat-' + category);
                
                if (selectedCategories.length === 0 || selectedCategories.includes(category)) {
                    cells.forEach(cell => cell.classList.remove('col-hidden'));
                } else {
                    cells.forEach(cell => cell.classList.add('col-hidden'));
                }
            });

            localStorage.setItem('sportabzeichen_view_selection', JSON.stringify(selectedCategories));
        });

        // Restore Selection
        const savedSelection = localStorage.getItem('sportabzeichen_view_selection');
        if (savedSelection) {
            try {
                $viewSelector.val(JSON.parse(savedSelection)).trigger('change');
            } catch(e) { console.error('Storage Error', e); }
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

    // --- INITIALISIERUNG ---
    // Hints aktualisieren und Verbands-Inputs sperren
    document.querySelectorAll('.js-discipline-select').forEach(select => {
        updateRequirementHints(select);
        checkVerbandInput(select);
    });

    // --- CHANGE LISTENER (Delegate) ---
    form.addEventListener('change', async function(event) {
        const el = event.target;
        
        // Nur Elemente mit data-save verarbeiten
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

        // UI-Referenzen
        const selectEl = cell ? cell.querySelector('select') : null;
        const inputEl = cell ? cell.querySelector('input[type="text"]') : null;

        // A) SCHWIMM-NACHWEIS SELECT
        if (type === 'swimming_select') {        
            targetRoute = swimmingRoute;
            payload.discipline_id = el.value;
        } 
        // B) NORMALE DISZIPLINEN & LEISTUNGEN
        else {
            if (!selectEl || !selectEl.value || !epId) return;

            // Route: Select geändert -> Disziplin speichern; Text geändert -> Ergebnis speichern
            targetRoute = (el.tagName === 'SELECT') ? disciplineRoute : resultRoute;

            if (el.tagName === 'SELECT') {
                updateRequirementHints(el);
                checkVerbandInput(el); // UI sofort sperren/entsperren
            }

            payload.discipline_id = selectEl.value;
            // Wert aus Input (Zahl oder Text wie "DLRG")
            payload.leistung = inputEl ? inputEl.value : '';

            // Visuelles Feedback: Input sperren während Request
            if (inputEl && !inputEl.disabled) {
                inputEl.setAttribute('data-temp-disabled', 'true');
                inputEl.disabled = true;
                inputEl.style.opacity = '0.6';
            }
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

            const data = await response.json();
            
            // Input wieder freigeben
            if (inputEl && inputEl.hasAttribute('data-temp-disabled')) {
                inputEl.disabled = false;
                inputEl.removeAttribute('data-temp-disabled');
                inputEl.style.opacity = '1';
                inputEl.focus(); // Fokus zurückgeben
            }

            if (data.status === 'ok' || data.success) {
                // 1. Zellen-Update (Farben etc.)
                if (type !== 'swimming_select' && cell) {
                    handleDisciplineColors(data, cell, row, kat, el);
                }
                
                // 2. Gesamt-Update (Punkte/Medaillen Widget)
                updateUIWidgets(epId, row, data);
            } else {
                throw new Error(data.message || 'Fehler beim Speichern');
            }
        } catch (e) {
            console.error('Fehler:', e);
            if (inputEl) {
                inputEl.disabled = false;
                inputEl.style.backgroundColor = '#ffe6e6'; // Rot markieren
                setTimeout(() => inputEl.style.backgroundColor = '', 3000);
            }
            alert('Fehler beim Speichern: ' + e.message);
        }
    });

    // --- CLICK LISTENER (Schwimmen Löschen) ---
    document.addEventListener('click', async function(event) {
        // Delegate: Prüfen ob Click innerhalb des Buttons war
        const btn = event.target.closest('.btn-delete-swimming');
        if (!btn) return;

        event.preventDefault();
        const epId = btn.getAttribute('data-ep-id');
        
        if (!swimmingDeleteRoute || !epId) return;
        
        if(!confirm('Schwimmnachweis wirklich entfernen?')) return;

        btn.style.opacity = '0.5';
        btn.disabled = true;

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
                const row = btn.closest('tr'); // Falls Widget im Row ist
                // Widget neu rendern
                updateUIWidgets(epId, row, data);
            } else {
                alert('Fehler: ' + (data.message || 'Konnte nicht gelöscht werden.'));
            }
        } catch (e) {
            console.error('Fehler beim Löschen:', e);
            alert('Server-Fehler beim Löschen.');
        } finally {
            btn.style.opacity = '1';
            btn.disabled = false;
        }
    });

    // =========================================================
    // 3. HELPER FUNCTIONS
    // =========================================================

    /**
     * Sperrt das Eingabefeld, wenn Unit=NONE (z.B. Verbandsabzeichen).
     */
    function checkVerbandInput(selectEl) {
        const cell = selectEl.closest('td');
        const inputEl = cell.querySelector('input[type="text"]');
        if (!inputEl) return;

        const selectedOption = selectEl.options[selectEl.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            // Keine Auswahl -> Reset
            inputEl.disabled = true;
            inputEl.value = '';
            inputEl.classList.remove('bg-light');
            return;
        }
        
        const unit = selectedOption.getAttribute('data-unit'); 
        const verbandName = selectedOption.getAttribute('data-verband');

        const isUnitNone = (unit === 'NONE' || unit === 'UNIT_NONE');

        if (isUnitNone) {
            // Pauschal-Eintrag (Verband)
            inputEl.value = verbandName || 'Nachweis';
            inputEl.disabled = true; // User kann nichts tippen
            inputEl.classList.add('bg-light');
        } else {
            // Normale Eingabe
            inputEl.disabled = false;
            inputEl.classList.remove('bg-light');
            
            // Aufräumen, falls vorher Text drin stand
            if (inputEl.value === verbandName || inputEl.value === 'Nachweis') {
                inputEl.value = '';
            }
        }
    }

    /**
     * Setzt Farben (Gold/Silber/Bronze) und leert Konkurrenzfelder.
     */
    function handleDisciplineColors(data, cell, row, kat, el) {
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        const isSelect = (el.tagName === 'SELECT');

        // 1. Farben setzen
        const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
        [selectEl, inputEl].forEach(element => {
            if(element) {
                element.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
                element.classList.add('medal-' + resultColor);
            }
        });

        // 2. Verbands-Logik erneut prüfen (falls Backend Daten geändert hat)
        if (isSelect) {
            checkVerbandInput(selectEl);
        }

        // 3. Andere Felder der gleichen Kategorie leeren
        if (isSelect && kat) {
            row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(otherEl => {
                // Überspringen, wenn es das aktuelle Element ist
                if (otherEl.closest('td') === cell) return;

                if (otherEl.tagName === 'INPUT') {
                    otherEl.value = '';
                    otherEl.disabled = true; // Erst disablen, bis Disziplin gewählt wird
                    otherEl.classList.remove('bg-light');
                    otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                    otherEl.classList.add('medal-none');
                }
                if (otherEl.tagName === 'SELECT') {
                    otherEl.value = ''; 
                    otherEl.classList.remove('medal-gold', 'medal-silber', 'medal-bronze');
                    otherEl.classList.add('medal-none');
                    // Hints resetten
                    updateRequirementHints(otherEl);
                }
            });
        }
    }

    /**
     * Aktualisiert Gesamtpunkte, Medaille und Schwimmstatus im DOM.
     */
    function updateUIWidgets(epId, row, data) {
        
        // A. Gesamtpunkte
        const totalBadge = document.getElementById('total-points-' + epId);
        if (totalBadge && data.total !== undefined) {
            totalBadge.textContent = data.total;
            
            // Animation
            totalBadge.classList.add('text-success');
            setTimeout(() => totalBadge.classList.remove('text-success'), 1000);
        }

        // B. Medaille
        const medalBadge = document.getElementById('final-medal-' + epId);
        if (medalBadge) {
            const medal = data.medal ? String(data.medal).toLowerCase() : 'none';
            const labelEl = medalBadge.querySelector('.badge-label-small');

            medalBadge.className = 'result-badge-box'; // Reset Basis-Klasse
            
            if (medal === 'gold') {
                medalBadge.classList.add('bg-gold');
                medalBadge.textContent = 'Gold';
            } else if (medal === 'silver' || medal === 'silber') {
                medalBadge.classList.add('bg-silver');
                medalBadge.textContent = 'Silber';
            } else if (medal === 'bronze') {
                medalBadge.classList.add('bg-bronze');
                medalBadge.textContent = 'Bronze';
            } else {
                medalBadge.classList.add('medal-none');
                medalBadge.textContent = '-';
            }
            // Falls du das Label (span) behalten willst, musst du es hier wieder einfügen
            // oder textContent nur auf den TextNode anwenden.
        }

        // C. Schwimm-Container
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
                
                if(infoText) {
                    const txt = data.swimming_met_via || data.met_via || 'Erledigt';
                    infoText.textContent = txt;
                    infoText.title = txt + (data.expiry ? ' (bis ' + data.expiry + ')' : '');
                }
            } else {
                if(badgeCont) badgeCont.classList.add('d-none');
                if(dropCont)  dropCont.classList.remove('d-none');
                if(select)    select.value = ""; 
            }
        }
    }

    /**
     * Zeigt Bronze/Silber/Gold Werte unter dem Input an.
     */
    function updateRequirementHints(select) {
        const parentTd = select.closest('td');
        if (!parentTd) return;

        const opt = select.options[select.selectedIndex];
        
        // Suche nach den Elementen (Klassen anpassen falls nötig)
        const labels = {
            b: parentTd.querySelector('.req-val-b, .js-val-b'),
            s: parentTd.querySelector('.req-val-s, .js-val-s'),
            g: parentTd.querySelector('.req-val-g, .js-val-g'),
            unit: parentTd.querySelector('.req-unit, .input-group-text-unit')
        };
        
        // Input freischalten/sperren
        const input = parentTd.querySelector('input[data-type="leistung"]');

        if (!opt || !opt.value) {
            // Alles resetten
            Object.values(labels).forEach(l => l && (l.textContent = (l === labels.unit ? '' : '-')));
            if(input) input.disabled = true;
            return;
        }

        // Werte aus HTML data-Attributen
        if(labels.b) labels.b.textContent = opt.getAttribute('data-bronze') || '-';
        if(labels.s) labels.s.textContent = opt.getAttribute('data-silber') || '-';
        if(labels.g) labels.g.textContent = opt.getAttribute('data-gold') || '-';
        
        if(labels.unit) {
            const u = opt.getAttribute('data-unit');
            const showUnit = (u && u !== 'NONE' && u !== 'UNIT_NONE');
            labels.unit.textContent = showUnit ? u : '';
        }
        
        if(input) input.disabled = false;
    }
});