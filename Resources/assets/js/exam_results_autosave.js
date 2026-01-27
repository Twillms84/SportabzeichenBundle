document.addEventListener('DOMContentLoaded', function() {
    
    const $ = jQuery; 

    // =========================================================
    // 1. ANSICHT & FILTER
    // =========================================================
    const $viewSelector = $('#viewSelector'); 
    
    if ($viewSelector.length) {
        $viewSelector.on('changed.bs.select', function (e, clickedIndex, isSelected, previousValue) {
            const selectedCategories = $(this).val() || [];
            
            $('#viewSelector option').each(function() {
                const category = $(this).val(); 
                const cells = document.querySelectorAll('.col-cat-' + category);
                const showAll = selectedCategories.length === 0;
                
                if (showAll || selectedCategories.includes(category)) {
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
                    $viewSelector.selectpicker('val', parsed);
                }
                $viewSelector.selectpicker('refresh');
                $viewSelector.trigger('changed.bs.select');
            } catch(e) { console.error('Storage Error', e); }
        }
    }

    // =========================================================
    // 1b. TEILNEHMER FILTER
    // =========================================================
    const $classFilterSelect = $('#client-class-filter');
    const searchInput = document.getElementById('client-search-input');
    
    if ($classFilterSelect.length && searchInput) {
        // Wir holen die Rows einmalig, um das Dropdown zu füllen
        const allRows = document.querySelectorAll('.participant-row');
        const classes = new Set();

        // Dropdown füllen: Mit .trim() säubern, um Leerzeichen-Fehler zu vermeiden
        allRows.forEach(row => {
            const rawClass = row.getAttribute('data-class');
            if (rawClass) {
                const cls = rawClass.trim();
                if (cls !== '') classes.add(cls);
            }
        });

        // Optionen alphabetisch sortiert einfügen
        Array.from(classes).sort().forEach(cls => {
            $classFilterSelect.append(`<option value="${cls}">${cls}</option>`);
        });
        
        // Selectpicker aktualisieren (falls vorhanden)
        if ($.fn.selectpicker) {
            $classFilterSelect.selectpicker('refresh');
        }

        // Die Filter-Logik
        const filterRows = () => {
            // 1. Hole die ausgewählten Werte sicher als Array
            let selectedClasses = $classFilterSelect.val();

            // Fix: Sicherstellen, dass es immer ein Array ist (auch bei Single-Select oder null)
            if (!selectedClasses) {
                selectedClasses = [];
            } else if (!Array.isArray(selectedClasses)) {
                selectedClasses = [selectedClasses];
            }

            const searchTerm = searchInput.value.toLowerCase().trim();
            
            // Wichtig: Rows neu abfragen, falls sie dynamisch geladen wurden
            const currentRows = document.querySelectorAll('.participant-row');

            currentRows.forEach(row => {
                // Daten aus der Zeile holen und säubern
                const rowClass = (row.getAttribute('data-class') || '').trim();
                const nameEl = row.querySelector('.name-main');
                const nameText = nameEl ? nameEl.textContent.toLowerCase() : ''; 

                // Logik Prüfung
                // Zeige an, wenn KEINE Klasse gewählt ist (Länge 0) ODER die Zeilen-Klasse im Array ist
                const matchClass = (selectedClasses.length === 0 || selectedClasses.includes(rowClass));
                const matchSearch = (searchTerm === '' || nameText.includes(searchTerm));

                // Sichtbarkeit setzen
                if (matchClass && matchSearch) {
                    row.style.display = ''; // Anzeigen (Standard)
                } else {
                    row.style.display = 'none'; // Ausblenden
                }
            });
            const $printBtn = $('#btn-print-groupcard'); 

            if ($printBtn.length) {
                // 2. Basis-URL holen (beim ersten Mal speichern wir sie in einem Attribut, damit wir sie nicht verlieren)
                if (!$printBtn.data('base-href')) {
                    $printBtn.data('base-href', $printBtn.attr('href'));
                }
                const baseUrl = $printBtn.data('base-href');

                // 3. Parameter bauen
                // Wir nehmen den ersten gewählten Filter (falls Multiselect, entscheiden wir uns für den ersten oder joinen)
                const selectedClass = (Array.isArray(selectedClasses) && selectedClasses.length > 0) 
                                    ? selectedClasses[0] // Oder selectedClasses.join(',')
                                    : '';

                // 4. URL aktualisieren
                // Prüfen, ob schon ein '?' in der URL ist
                const separator = baseUrl.includes('?') ? '&' : '?';
                const newUrl = baseUrl + separator + 'class_filter=' + encodeURIComponent(selectedClass);

                $printBtn.attr('href', newUrl);
            }
        };

        // Event Listener: Auf 'change' UND 'changed.bs.select' hören für maximale Kompatibilität
        $classFilterSelect.on('change changed.bs.select', filterRows);
        
        searchInput.addEventListener('keyup', filterRows);
        searchInput.addEventListener('input', filterRows);
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

    // Initiale UI Checks
    document.querySelectorAll('.js-discipline-select').forEach(select => {
        updateRequirementHints(select);
        checkVerbandInput(select);
    });

    // --- CHANGE LISTENER (SPEICHERN) ---
    form.addEventListener('change', async function(event) {
        const el = event.target;
        if (!el.hasAttribute('data-save')) return;

        const epId = el.getAttribute('data-ep-id');
        const type = el.getAttribute('data-type'); 
        const kat = el.getAttribute('data-kategorie');
        const cell = el.closest('td');
        const row = el.closest('tr');
        
        let targetRoute = '';
        let payload = { ep_id: epId, _token: csrfToken };

        const selectEl = cell ? cell.querySelector('select') : null;
        const inputEl = cell ? cell.querySelector('input[type="text"]') : null;

        if (type === 'swimming_select') {        
            targetRoute = swimmingRoute;
            payload.discipline_id = el.value;
        } else {
            if (!selectEl || !selectEl.value || !epId) return;
            targetRoute = (el.tagName === 'SELECT') ? disciplineRoute : resultRoute;

            if (el.tagName === 'SELECT') {
                updateRequirementHints(el);
                checkVerbandInput(el);
            }

            payload.discipline_id = selectEl.value;
            payload.leistung = inputEl ? inputEl.value : '';

            // Input sperren während Request
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
            
            // Input entsperren
            if (inputEl && inputEl.hasAttribute('data-temp-disabled')) {
                inputEl.disabled = false;
                inputEl.removeAttribute('data-temp-disabled');
                inputEl.style.opacity = '1';
                inputEl.focus();
            }

            if (data.status === 'ok' || data.success) {
                // UI Updates vom Try/Catch entkoppeln, damit Fehler hier nicht als "Netzwerkfehler" gelten
                try {
                    // 1. Zelle aktualisieren
                    if (type !== 'swimming_select' && cell) {
                        handleDisciplineColors(data, cell, row, kat, el);
                        if (data.new_requirements) updateRequirementsBadges(cell, data.new_requirements);
                    }
                    // 2. Live Update Widgets
                    updateUIWidgets(epId, row, data);
                } catch (uiErr) {
                    console.error('UI Update Warning:', uiErr);
                }

            } else {
                throw new Error(data.message || 'Fehler beim Speichern');
            }
        } catch (e) {
            console.error('Save Error:', e);
            if (inputEl) {
                inputEl.disabled = false;
                inputEl.classList.add('bg-danger', 'bg-opacity-25');
                setTimeout(() => inputEl.classList.remove('bg-danger', 'bg-opacity-25'), 3000);
            }
        }
    });

    // --- CLICK LISTENER (Schwimmen LÖSCHEN) ---
    document.addEventListener('click', async function(event) {
        const btn = event.target.closest('.btn-delete-swimming');
        if (!btn) return;

        event.preventDefault();
        
        const epId = btn.getAttribute('data-ep-id');
        const proofYear = String(btn.getAttribute('data-year') || '');
        const currentYear = String(btn.getAttribute('data-current-year') || '');
        const sourceRaw = btn.getAttribute('data-source') || '';
        const sourceUpper = sourceRaw.toUpperCase();

        if (proofYear && currentYear && proofYear !== currentYear) {
            alert(`Jahr ${proofYear} kann hier nicht gelöscht werden.`);
            return;
        }

        const forbiddenSources = ['AUSDAUER', 'SCHNELLIGKEIT', 'ENDURANCE', 'SPEED'];
        if (forbiddenSources.some(s => sourceUpper.includes(s))) {
            alert(`Automatisch durch "${sourceRaw}" erbracht. Bitte dort löschen.`);
            return;
        }

        if (!confirm('Wirklich löschen?')) return;

        const originalContent = btn.innerHTML;
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

            // Erfolgsfall prüfen (egal ob data.status oder data.success)
            if (data.status === 'ok' || data.success === true) {
                
                // UI Logik in eigenen Block, damit Fehler hier nicht in den Catch rutschen
                try {
                    const row = btn.closest('tr');
                    updateUIWidgets(epId, row, data);

                    const wrapper = document.getElementById('swimming-wrapper-' + epId);
                    if (wrapper) {
                        // Reset UI manuell erzwingen, falls updateUIWidgets es übersehen hat
                        const badgeCont = wrapper.querySelector('.swim-badge-container');
                        const dropCont = wrapper.querySelector('.swim-dropdown-container');
                        
                        if(badgeCont) badgeCont.classList.add('d-none');
                        if(dropCont) dropCont.classList.remove('d-none');
                        
                        // Select zurücksetzen
                        const select = wrapper.querySelector('select');
                        if (select) select.value = "";
                        
                        // Text leeren
                        const textEl = wrapper.querySelector('.swim-info-text');
                        if(textEl) textEl.textContent = '';
                    }
                } catch(uiError) {
                    console.error('Löschen UI Update Error:', uiError);
                }

            } else {
                alert('Fehler: ' + (data.message || 'Fehler beim Löschen.'));
            }
        } catch (e) {
            console.error('Delete Request Error:', e);
            alert('Kommunikationsfehler: Der Server konnte nicht erreicht werden.');
        } finally {
            if(btn) {
                btn.style.opacity = '1';
                btn.disabled = false;
                btn.innerHTML = originalContent;
            }
        }
    });

    // =========================================================
    // 3. UI HELFER
    // =========================================================

    function updateUIWidgets(epId, row, data) {
        // A. GESAMTPUNKTE
        const pointsValue = (data.total !== undefined) ? data.total : data.total_points;
        const totalBadge = document.getElementById('total-points-' + epId);
        
        if (totalBadge && pointsValue !== undefined) {
            totalBadge.textContent = pointsValue;
            triggerPulse(totalBadge);
        }

        // B. FINAL MEDAILLE (Live Update)
        const medalBadge = document.getElementById('final-medal-' + epId);
        let medalValue = data.final_medal || data.medal || 'none';
        medalValue = String(medalValue).toLowerCase();

        if (medalBadge) {
            const labelSpan = medalBadge.querySelector('.js-medal-label');
            
            // 1. RESET
            medalBadge.className = 'result-badge-box'; 

            // 2. Deine Farb-Klassen anwenden
            let labelText = '-';
            let colorClass = 'bg-light text-muted'; 

            switch (medalValue) {
                case 'gold':
                    colorClass = 'medal-gold'; 
                    labelText = 'Gold';
                    break;
                case 'silver':
                case 'silber':
                    colorClass = 'medal-silber'; 
                    labelText = 'Silber';
                    break;
                case 'bronze':
                    colorClass = 'medal-bronze'; 
                    labelText = 'Bronze';
                    break;
                default:
                    colorClass = ''; 
                    labelText = '-';
            }

            if(colorClass) medalBadge.classList.add(colorClass);

            if (labelSpan) labelSpan.textContent = labelText;
            else medalBadge.textContent = labelText;

            triggerPulse(medalBadge);
        }

        // C. SCHWIMMEN (Nur noch Wrapper Umschaltung: Badge vs. Dropdown)
        const hasSwimming = (data.has_swimming === true || data.has_swimming === 1 || String(data.has_swimming) === '1');
        
        // --- HIER WURDE DER ICON-CODE ENTFERNT ---
        
        // Wrapper Bereiche umschalten
        const wrapper = document.getElementById('swimming-wrapper-' + epId);
        if (wrapper) {
            const badgeCont = wrapper.querySelector('.swim-badge-container');
            const dropCont = wrapper.querySelector('.swim-dropdown-container');
            const textEl = wrapper.querySelector('.swim-info-text');

            if (hasSwimming) {
                // TEXT UPDATE LOGIK
                if (textEl) {
                    let displayName = '';

                    // 1. Priorität: Server Antwort (außer es enthält "DISCIPLINE")
                    if (data.swimming_name && !String(data.swimming_name).includes('DISCIPLINE')) {
                         displayName = data.swimming_name;
                    }

                    // 2. Fallback: Dropdown Text
                    if (!displayName) {
                        const select = wrapper.querySelector('select');
                        if (select && select.selectedIndex > -1) {
                            const selectedOption = select.options[select.selectedIndex];
                            if (selectedOption.value) displayName = selectedOption.text;
                        }
                    }

                    // 3. Notlösung
                    if (!displayName) displayName = 'Nachweis erbracht';

                    textEl.textContent = displayName;
                }

                if(badgeCont) badgeCont.classList.remove('d-none');
                if(dropCont) dropCont.classList.add('d-none');
            } else {
                // RESET
                if(badgeCont) badgeCont.classList.add('d-none');
                if(dropCont) dropCont.classList.remove('d-none');
                if (textEl) textEl.textContent = '';
                
                const select = wrapper.querySelector('select');
                if (select) select.value = "";
            }
        }
    }

    // --- KLEINE HELFER ---

    function triggerPulse(element) {
        if(!element) return;
        element.style.transition = "transform 0.2s ease-in-out";
        element.style.transform = "scale(1.1)";
        setTimeout(() => element.style.transform = "scale(1)", 200);
    }

    function removeMedalClasses(el) {
        el.classList.remove('medal-gold', 'medal-silber', 'medal-bronze', 'medal-none');
    }

    function updateRequirementsBadges(cell, req) {
        const badgeB = cell.querySelector('.req-val-b');
        const badgeS = cell.querySelector('.req-val-s');
        const badgeG = cell.querySelector('.req-val-g');
        if(badgeB) badgeB.textContent = req.bronze;
        if(badgeS) badgeS.textContent = req.silber;
        if(badgeG) badgeG.textContent = req.gold;
    }

    function checkVerbandInput(selectEl) {
        const cell = selectEl.closest('td');
        const inputEl = cell.querySelector('input[type="text"]');
        if (!inputEl) return;

        const selectedOption = selectEl.options[selectEl.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            inputEl.disabled = true;
            inputEl.value = '';
            removeMedalClasses(inputEl);
            removeMedalClasses(selectEl);
            return;
        }
        
        const unit = selectedOption.getAttribute('data-unit'); 
        const implicitPoints = parseInt(selectedOption.getAttribute('data-implicit-points') || 0);
        const isVerband = (unit === 'NONE' || unit === 'UNIT_NONE' || implicitPoints > 0);

        if (isVerband) {
            inputEl.value = ''; 
            inputEl.setAttribute('placeholder', '✓');
            inputEl.disabled = true; 
            inputEl.classList.add('bg-light');
            
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

    function handleDisciplineColors(data, cell, row, kat, el) {
        const selectEl = cell.querySelector('select');
        const inputEl = cell.querySelector('input[type="text"]');
        const isSelect = (el.tagName === 'SELECT');

        const resultColor = data.stufe ? data.stufe.toLowerCase() : 'none'; 
        let cssClass = 'medal-' + resultColor;
        if(resultColor === 'silver') cssClass = 'medal-silber';

        [selectEl, inputEl].forEach(element => {
            if(element) {
                removeMedalClasses(element);
                element.classList.add(cssClass);
            }
        });

        if (data.discipline_unit === 'NONE' || data.discipline_unit === 'UNIT_NONE') {
            if (inputEl) {
                inputEl.value = ''; 
                inputEl.disabled = true; 
                inputEl.setAttribute('placeholder', '✓');
                inputEl.classList.add('bg-light');
                removeMedalClasses(inputEl);
                inputEl.classList.add('medal-gold');
            }
            if (selectEl) {
                removeMedalClasses(selectEl);
                selectEl.classList.add('medal-gold');
            }
        } else if (isSelect) {
            checkVerbandInput(selectEl);
        }

        if (isSelect && kat) {
            row.querySelectorAll(`[data-kategorie="${kat}"]`).forEach(otherEl => {
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
            Object.values(labels).forEach(l => l && (l.textContent = ''));
        } else {
            if(labels.b) labels.b.textContent = opt.getAttribute('data-bronze') || '-';
            if(labels.s) labels.s.textContent = opt.getAttribute('data-silber') || '-';
            if(labels.g) labels.g.textContent = opt.getAttribute('data-gold') || '-';
            if(labels.unit) labels.unit.textContent = prettyUnit;
        }
    }
});