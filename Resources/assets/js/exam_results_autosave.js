document.addEventListener('DOMContentLoaded', function() {
    
    // =========================================================
    // 1. ANSICHT FILTER (Select2 / Category Logic)
    // =========================================================
    const $viewSelector = $('#viewSelector'); // jQuery für Select2
    
    // Initialisiere Select2 falls vorhanden (Bootstrap Theme empfohlen)
    
    if ($viewSelector.length) {
        $viewSelector.on('change', function() {
            const selectedCategories = $(this).val() || [];
            
            // Iteriere über alle Optionen des Filters (Ausdauer, Kraft, etc.)
            $('#viewSelector option').each(function() {
                const category = $(this).val(); // z.B. "Ausdauer"
                // CSS Klasse im HTML ist .col-cat-Ausdauer
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
                const parsed = JSON.parse(savedSelection);
                if(parsed && parsed.length > 0) {
                    $viewSelector.val(parsed).trigger('change');
                }
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
    // Hints aktualisieren und Verbands-Inputs sperren beim Laden
    document.querySelectorAll('.js-discipline-select').forEach(select => {
        updateRequirementHints(select);
        checkVerbandInput(select);
    });

    // --- CHANGE LISTENER (Delegate) ---
    form.addEventListener('change', async function(event) {
        const el = event.target;
        
        // Nur Elemente mit data-save="true" verarbeiten
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
            // Wert aus Input
            payload.leistung = inputEl ? inputEl.value : '';

            // Visuelles Feedback: Input sperren während Request
            if (inputEl && !inputEl.disabled && type === 'leistung') {
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
                inputEl.focus();
            }

            if (data.status === 'ok' || data.success) {
                // 1. Zellen-Update (Farben etc.)
                if (type !== 'swimming_select' && cell) {
                    handleDisciplineColors(data, cell, row, kat, el);

                    // Requirements Update aus Server-Antwort
                    if (data.new_requirements) {
                        const req = data.new_requirements;
                        const badgeB = cell.querySelector('.req-val-b');
                        const badgeS = cell.querySelector('.req-val-s');
                        const badgeG = cell.querySelector('.req-val-g');
                        
                        if(badgeB) badgeB.textContent = req.bronze;
                        if(badgeS) badgeS.textContent = req.silber;
                        if(badgeG) badgeG.textContent = req.gold;
                    }
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
                inputEl.classList.add('bg-danger', 'bg-opacity-25');
                setTimeout(() => inputEl.classList.remove('bg-danger', 'bg-opacity-25'), 3000);
            }
            // Optional: Toast Nachricht anzeigen statt Alert
        }
    });

    // --- CLICK LISTENER (Schwimmen Löschen) ---
    document.addEventListener('click', async function(event) {
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
                const row = btn.closest('tr'); 
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

    function checkVerbandInput(selectEl) {
        const cell = selectEl.closest('td');
        const inputEl = cell.querySelector('input[type="text"]');
        if (!inputEl) return;

        const selectedOption = selectEl.options[selectEl.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            // Keine Auswahl -> Reset
            inputEl.disabled = true;
            inputEl.value = '';
            removeMedalClasses(inputEl);
            removeMedalClasses(selectEl);
            return;
        }
        
        const unit = selectedOption.getAttribute('data-unit'); 
        const implicitPoints = parseInt(selectedOption.getAttribute('data-implicit-points') || 0);
        // Prüft, ob es ein Verbandsabzeichen ist (3 Punkte pauschal oder Unit NONE)
        const isVerband = (unit === 'NONE' || unit === 'UNIT_NONE' || implicitPoints > 0);

        if (isVerband) {
            inputEl.value = ''; 
            inputEl.setAttribute('placeholder', '✓'); // Häkchen als Placeholder
            inputEl.disabled = true; 
            inputEl.classList.add('bg-light');
            
            // Visuell auf Gold setzen
            removeMedalClasses(inputEl);
            removeMedalClasses(selectEl);
            inputEl.classList.add('medal-gold');
            selectEl.classList.add('medal-gold');
        } else {
            inputEl.disabled = false;
            inputEl.setAttribute('placeholder', '');
            inputEl.classList.remove('bg-light');
            
            if(inputEl.value === '') {
                removeMedalClasses(inputEl);
                removeMedalClasses(selectEl);
                inputEl.classList.add('medal-none');
                selectEl.classList.add('medal-none');
            }
        }
    }

    function removeMedalClasses(el) {
        el.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
    }

    function handleDisciplineColors(data, cell, row, kat, el) {
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        const isSelect = (el.tagName === 'SELECT');

        // 1. Basisfarben anhand der Stufe setzen (Bronze/Silber/Gold/None)
        const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
        let cssClass = 'medal-' + resultColor;
        if(resultColor === 'silver') cssClass = 'medal-silber';

        [selectEl, inputEl].forEach(element => {
            if(element) {
                removeMedalClasses(element);
                element.classList.add(cssClass);
            }
        });

        // -----------------------------------------------------------
        // NEU: Reaktion auf Server-Antwort (Verband / Unit NONE)
        // -----------------------------------------------------------
        // Wenn der Server sagt: "Keine Einheit" (NONE) oder explizit Stufe GOLD mit 3 Punkten ohne Leistung
        if (data.discipline_unit === 'NONE' || data.discipline_unit === 'UNIT_NONE') {
            if (inputEl) {
                inputEl.value = ''; 
                inputEl.disabled = true; // SPERREN
                inputEl.setAttribute('placeholder', '✓'); // Haken anzeigen
                inputEl.classList.add('bg-light');
                
                // Visuell zwingend auf Gold setzen
                removeMedalClasses(inputEl);
                inputEl.classList.add('medal-gold');
            }
            if (selectEl) {
                removeMedalClasses(selectEl);
                selectEl.classList.add('medal-gold');
            }
        } 
        // Falls Server normale Einheit schickt, aber wir HTML-Logik prüfen müssen
        else if (isSelect) {
            checkVerbandInput(selectEl);
        } 
        // Falls Eingabefeld geändert wurde und KEIN "NONE" zurückkam -> Entsperren prüfen
        else {
             if (selectEl) {
                 // Sicherheitscheck: Passen HTML-Select und Server-Antwort zusammen?
                 checkVerbandInput(selectEl); 
             }
        }
        // -----------------------------------------------------------


        // 3. Andere Felder der gleichen Kategorie leeren (Exklusivität)
        if (isSelect && kat) {
            row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(otherEl => {
                // Überspringen, wenn es das aktuelle Element ist (oder im gleichen TD)
                if (otherEl.closest('td') === cell) return;

                if (otherEl.tagName === 'INPUT') {
                    otherEl.value = '';
                    otherEl.disabled = true;
                    otherEl.classList.remove('bg-light');
                    removeMedalClasses(otherEl);
                    otherEl.classList.add('medal-none');
                }
                if (otherEl.tagName === 'SELECT') {
                    otherEl.value = ''; 
                    removeMedalClasses(otherEl);
                    otherEl.classList.add('medal-none');
                    updateRequirementHints(otherEl);
                }
            });
        }
    }

    function updateUIWidgets(epId, row, data) {
        // console.log("Debug Data:", data); // Kannst du später auskommentieren

        // ---------------------------------------------------------
        // A. GESAMTPUNKTE (Server schickt 'total_points')
        // ---------------------------------------------------------
        // Wir prüfen auf 'total' ODER 'total_points', um sicher zu sein
        const pointsValue = (data.total !== undefined) ? data.total : data.total_points;
        const totalBadge = document.getElementById('total-points-' + epId);
        
        if (totalBadge && pointsValue !== undefined) {
            totalBadge.textContent = pointsValue;
            // Kleiner Highlight-Effekt
            totalBadge.classList.add('text-success', 'fw-bold');
            setTimeout(() => totalBadge.classList.remove('text-success', 'fw-bold'), 1000);
        }

        // ---------------------------------------------------------
        // B. MEDAILLE (Server schickt 'final_medal')
        // ---------------------------------------------------------
        const medalBadge = document.getElementById('final-medal-' + epId);
        
        // WICHTIG: Hier nehmen wir den Wert, den der Server laut Log schickt ('final_medal')
        // Fallback auf 'medal', falls sich das Backend mal ändert.
        let medalValue = data.final_medal || data.medal || 'none';
        medalValue = String(medalValue).toLowerCase();

        if (medalBadge) {
            const labelSpan = medalBadge.querySelector('.js-medal-label');
            
            // Alte Klassen entfernen (aber Layout-Klassen behalten)
            medalBadge.classList.remove(
                'bg-warning', 'border-warning', 
                'bg-secondary', 'border-secondary', 
                'bg-danger', 'border-danger', 
                'bg-light', 'text-muted', 'text-dark', 
                'bg-opacity-25'
            );
            
            // Sicherstellen, dass Basisklassen da sind
            medalBadge.classList.add('badge', 'border');

            let labelText = '-';
            
            if (medalValue === 'gold') {
                medalBadge.classList.add('bg-warning', 'bg-opacity-25', 'border-warning', 'text-dark');
                labelText = 'Gold';
            } else if (medalValue === 'silver' || medalValue === 'silber') {
                medalBadge.classList.add('bg-secondary', 'bg-opacity-25', 'border-secondary', 'text-dark');
                labelText = 'Silber';
            } else if (medalValue === 'bronze') {
                medalBadge.classList.add('bg-danger', 'bg-opacity-25', 'border-danger', 'text-dark');
                labelText = 'Bronze';
            } else {
                medalBadge.classList.add('bg-light', 'text-muted');
            }
            
            if(labelSpan) labelSpan.textContent = labelText;
        }

        // ---------------------------------------------------------
        // C. SCHWIMMEN (Icon & Badge)
        // ---------------------------------------------------------
        const wrapper = document.getElementById('swimming-wrapper-' + epId);
        // WICHTIG: Das Log sagt "Element nicht gefunden". Das Element MUSS existieren (siehe Schritt 2 unten)
        const swimIcon = document.getElementById('swim-icon-' + epId); 
        
        const hasSwimming = (data.has_swimming === true || data.has_swimming === 1 || data.has_swimming === '1');

        // 1. Icon im Namen aktualisieren
        if(swimIcon) {
            if(hasSwimming) {
                // Klasse komplett neu setzen ist sicherer als add/remove hier
                swimIcon.className = 'fas fa-swimmer ms-2 text-success';
            } else {
                swimIcon.className = 'fas fa-swimmer ms-2 text-danger opacity-50';
            }
        }

        // 2. Container (Dropdown vs. Badge) umschalten
        if (wrapper) {
            const badgeCont = wrapper.querySelector('.swim-badge-container');
            const dropCont  = wrapper.querySelector('.swim-dropdown-container');
            const infoText  = wrapper.querySelector('.swim-info-text');
            const select    = wrapper.querySelector('select');

            if (hasSwimming) {
                if(badgeCont) badgeCont.classList.remove('d-none');
                if(dropCont)  dropCont.classList.add('d-none');
                
                if(infoText) {
                    // Server schickt laut Log: swimming_met_via
                    const txt = data.swimming_met_via || data.met_via || 'Nachweis erbracht';
                    infoText.textContent = txt;
                }
            } else {
                if(badgeCont) badgeCont.classList.add('d-none');
                if(dropCont)  dropCont.classList.remove('d-none');
                if(select)    select.value = ""; 
            }
        }
    }

    function updateRequirementHints(select) {
        const parentTd = select.closest('td');
        if (!parentTd) return;

        const opt = select.options[select.selectedIndex];
        
        const labels = {
            b: parentTd.querySelector('.req-val-b'),
            s: parentTd.querySelector('.req-val-s'),
            g: parentTd.querySelector('.req-val-g'),
            unit: parentTd.querySelector('.req-unit')
        };
        
        const input = parentTd.querySelector('input[data-type="leistung"]');

        if (!opt || !opt.value) {
            Object.values(labels).forEach(l => l && (l.textContent = ''));
            if(input) input.disabled = true;
            return;
        }

        const prettyUnit = opt.getAttribute('data-unit-label') || '';
        const implicitPoints = parseInt(opt.getAttribute('data-implicit-points') || 0);
        const unitRaw = opt.getAttribute('data-unit');
        const isVerband = (unitRaw === 'NONE' || unitRaw === 'UNIT_NONE' || implicitPoints > 0);

        if (isVerband) {
            // Hints leeren bei Verbandsabzeichen
            Object.values(labels).forEach(l => l && (l.textContent = ''));
        } else {
            if(labels.b) labels.b.textContent = opt.getAttribute('data-bronze') || '-';
            if(labels.s) labels.s.textContent = opt.getAttribute('data-silber') || '-';
            if(labels.g) labels.g.textContent = opt.getAttribute('data-gold') || '-';
            if(labels.unit) labels.unit.textContent = prettyUnit;
        }
    }
});