document.addEventListener('DOMContentLoaded', function() {
    
    // Wir nutzen jQuery für selectpicker Events, da Bootstrap-Select auf jQuery basiert
    const $ = jQuery; 

    // =========================================================
    // 1. ANSICHT FILTER (Disziplinen) - IServ Style
    // =========================================================
    const $viewSelector = $('#viewSelector'); 
    
    if ($viewSelector.length) {
        // Event Listener speziell für bootstrap-select
        $viewSelector.on('changed.bs.select', function (e, clickedIndex, isSelected, previousValue) {
            const selectedCategories = $(this).val() || [];
            
            // Iteriere über alle Optionen
            $('#viewSelector option').each(function() {
                const category = $(this).val(); 
                const cells = document.querySelectorAll('.col-cat-' + category);
                
                // Wenn nichts ausgewählt ist ODER die Kategorie im Array ist -> anzeigen
                // (Logik: Leere Auswahl = Alles anzeigen? Oder nichts? 
                //  Bei IServ Filtern bedeutet "Nichts gewählt" oft "Filter aus/Alles", 
                //  aber bei Multiple Select oft "Nichts". Ich setze hier: Leer = Alles anzeigen)
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
                    $viewSelector.selectpicker('val', parsed); // Setze Wert im Plugin
                }
                $viewSelector.selectpicker('refresh'); // UI aktualisieren
                $viewSelector.trigger('changed.bs.select'); // Filter sofort anwenden
            } catch(e) { console.error('Storage Error', e); }
        }
    }

    // =========================================================
    // 1b. TEILNEHMER FILTER (Klasse + Namenssuche) - IServ Style
    // =========================================================
    const $classFilterSelect = $('#client-class-filter'); // jQuery Selektor
    const searchInput = document.getElementById('client-search-input');
    
    if ($classFilterSelect.length && searchInput) {
        const rows = document.querySelectorAll('.participant-row');
        const classes = new Set();

        // A) Dropdown befüllen
        rows.forEach(row => {
            const cls = row.getAttribute('data-class');
            if (cls && cls.trim() !== '') {
                classes.add(cls);
            }
        });

        // Alphabetisch sortieren und in Select einfügen
        Array.from(classes).sort().forEach(cls => {
            // Wichtig: Option als String bauen oder normales JS Element
            $classFilterSelect.append(`<option value="${cls}">${cls}</option>`);
        });

        // WICHTIG: Damit IServ/Bootstrap das Dropdown rendert:
        $classFilterSelect.selectpicker('refresh');

        // B) Die kombinierte Filter-Funktion
        const filterRows = () => {
            // Hole Array der gewählten Klassen (z.B. ['5a', '5b'])
            const selectedClasses = $classFilterSelect.val() || [];
            const searchTerm = searchInput.value.toLowerCase().trim();

            rows.forEach(row => {
                const rowClass = row.getAttribute('data-class');
                const nameEl = row.querySelector('.name-main');
                const nameText = nameEl ? nameEl.textContent.toLowerCase() : ''; 

                // 1. Klasse prüfen
                // Zeige an, wenn KEINE Klasse ausgewählt ist (Filter inaktiv) 
                // ODER die Zeilen-Klasse in der Auswahl enthalten ist
                const matchClass = (selectedClasses.length === 0 || selectedClasses.includes(rowClass));

                // 2. Name prüfen
                const matchSearch = (searchTerm === '' || nameText.includes(searchTerm));

                if (matchClass && matchSearch) {
                    row.style.display = ''; 
                } else {
                    row.style.display = 'none'; 
                }
            });
        };

        // C) Event Listener
        // Bootstrap-Select Event
        $classFilterSelect.on('changed.bs.select', filterRows);
        
        // Input Events
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
        // Event Delegation: Prüfen, ob der Klick auf (oder in) .btn-delete-swimming war
        const btn = event.target.closest('.btn-delete-swimming');
        if (!btn) return;

        event.preventDefault();
        
        const epId = btn.getAttribute('data-ep-id');
        
        // --- NEUE LOGIK START ---
        
        // 1. Daten aus den Attributen lesen (werden im Twig gesetzt)
        const proofYear = String(btn.getAttribute('data-year') || '');
        const currentYear = String(btn.getAttribute('data-current-year') || '');
        const sourceRaw = btn.getAttribute('data-source') || '';
        const sourceUpper = sourceRaw.toUpperCase();

        // 2. Prüfung: Ist es das aktuelle Jahr?
        // (Nur prüfen, wenn beide Werte vorhanden sind)
        if (proofYear && currentYear && proofYear !== currentYear) {
            alert('Dieser Schwimmnachweis stammt aus dem Jahr ' + proofYear + ' und kann hier nicht gelöscht werden.');
            return;
        }

        // 3. Prüfung: Ist es ein automatischer Ersatz (Ausdauer/Schnelligkeit)?
        const forbiddenSources = ['AUSDAUER', 'SCHNELLIGKEIT', 'ENDURANCE', 'SPEED'];
        // Prüfen, ob der Source-String eines der verbotenen Wörter ENTHÄLT (z.B. "Ausdauer 800m")
        const isForbidden = forbiddenSources.some(s => sourceUpper.includes(s));

        if (isForbidden) {
            alert('Dieser Nachweis wurde automatisch durch die Disziplingruppe "' + sourceRaw + '" erbracht.\n\nEr kann nicht manuell gelöscht werden. Bitte entfernen Sie stattdessen die Leistung in der Disziplin ' + sourceRaw + '.');
            return;
        }

        // 4. Sicherheitsabfrage mit Disziplin-Name
        const confirmMsg = sourceRaw 
            ? 'Soll der Schwimmnachweis (' + sourceRaw + ') wirklich entfernt werden?'
            : 'Soll der manuelle Schwimmnachweis wirklich entfernt werden?';

        if (!confirm(confirmMsg)) {
            return;
        }

        // --- AJAX DELETE ---
        
        // Routen-Check
        if (!swimmingDeleteRoute || !epId) {
            console.error('Lösch-Route oder ID fehlt');
            return;
        }

        // UI Feedback: Button deaktivieren & Spinner (optional, falls Icon vorhanden)
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

            if (data.status === 'ok' || data.success) {
                // UI Widget Update (Punkte/Medaillen neu berechnen)
                const row = btn.closest('tr');
                updateUIWidgets(epId, row, data);

                // Spezifisches UI Update für den Schwimm-Bereich
                const wrapper = document.getElementById('swimming-wrapper-' + epId);
                if (wrapper) {
                    // Badge ausblenden
                    const badgeCont = wrapper.querySelector('.swim-badge-container');
                    if (badgeCont) badgeCont.classList.add('d-none');
                    
                    // Dropdown wieder einblenden
                    const dropCont = wrapper.querySelector('.swim-dropdown-container');
                    if (dropCont) dropCont.classList.remove('d-none');

                    // Select Reset
                    const select = wrapper.querySelector('select');
                    if (select) select.value = "";
                    
                    // Kleines Icon beim Namen aktualisieren
                    const swimIcon = document.getElementById('swim-icon-' + epId);
                    if(swimIcon) {
                        swimIcon.className = 'fas fa-swimmer ms-1 text-danger opacity-50'; // Klasse entsprechend deinem HTML anpassen
                    }
                }
            } else {
                alert('Fehler: ' + (data.message || 'Konnte nicht gelöscht werden.'));
            }
        } catch (e) {
            console.error('Fehler beim Löschen:', e);
            alert('Kommunikationsfehler mit dem Server.');
        } finally {
            // Button Reset (falls UI Update fehlschlug oder für Re-Use)
            btn.style.opacity = '1';
            btn.disabled = false;
            btn.innerHTML = originalContent;
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
        
        // A. GESAMTPUNKTE
        const pointsValue = (data.total !== undefined) ? data.total : data.total_points;
        const totalBadge = document.getElementById('total-points-' + epId);
        
        if (totalBadge && pointsValue !== undefined) {
            totalBadge.textContent = pointsValue;
            // Kurzes Aufblinken zur Bestätigung
            totalBadge.style.transform = "scale(1.2)";
            setTimeout(() => totalBadge.style.transform = "scale(1)", 200);
        }

        // B. MEDAILLE (Der CSS Fix)
        const medalBadge = document.getElementById('final-medal-' + epId);
        let medalValue = data.final_medal || data.medal || 'none';
        medalValue = String(medalValue).toLowerCase();

        if (medalBadge) {
            const labelSpan = medalBadge.querySelector('.js-medal-label') || medalBadge;

            // 1. ALLE Farbklassen entfernen (aggressiv)
            // Nutze remove mit Spread-Syntax oder einzeln, um sicherzugehen
            const classesToRemove = [
                'bg-warning', 'border-warning', 
                'bg-secondary', 'border-secondary', 
                'bg-danger', 'border-danger', 
                'bg-light', 'bg-dark', 'bg-white',
                'text-muted', 'text-dark', 'text-white',
                'bg-opacity-10', 'bg-opacity-25', 'bg-opacity-50'
            ];
            medalBadge.classList.remove(...classesToRemove);

            // 2. Basis-Klassen sicherstellen
            medalBadge.classList.add('badge', 'border');

            // 3. Neue Klassen setzen
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
                // Keine Medaille
                medalBadge.classList.add('bg-light', 'text-muted');
            }
            
            // Text setzen
            if (medalBadge.querySelector('.js-medal-label')) {
                medalBadge.querySelector('.js-medal-label').textContent = labelText;
            } else {
                medalBadge.textContent = labelText;
            }
        }

        // C. SCHWIMMEN (Bleibt wie gehabt, nur kurz der Vollständigkeit halber)
        const wrapper = document.getElementById('swimming-wrapper-' + epId);
        const hasSwimming = (data.has_swimming === true || data.has_swimming === 1 || data.has_swimming === '1');
        
        // Icon Update
        const swimIcon = document.getElementById('swim-icon-' + epId);
        if(swimIcon) {
            swimIcon.className = hasSwimming 
                ? 'fas fa-swimmer ms-2 text-success' 
                : 'fas fa-swimmer ms-2 text-danger opacity-50';
        }

        if (wrapper) {
            const badgeCont = wrapper.querySelector('.swim-badge-container');
            const dropCont  = wrapper.querySelector('.swim-dropdown-container'); // oder .swim-inputs-container je nach HTML
            
            if (hasSwimming) {
                if(badgeCont) badgeCont.classList.remove('d-none');
                if(dropCont)  dropCont.classList.add('d-none');
            } else {
                if(badgeCont) badgeCont.classList.add('d-none');
                if(dropCont)  dropCont.classList.remove('d-none');
                // Reset Inputs/Selects im UI
                const selects = wrapper.querySelectorAll('select');
                selects.forEach(s => s.value = "");
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