/**
 * PulsR Sportabzeichen Autosave Modul
 */
(function($) {
    // Wir registrieren das Modul direkt im IServ-Namespace
    IServ.SportabzeichenAutosave = (function() {

        function saveChange(el) {
            const epId = el.dataset.epId;
            const type = el.dataset.type;
            const disciplineId = el.dataset.disciplineId || (el.tagName === 'SELECT' ? el.value : null);
            const leistung = type === 'leistung' ? el.value : null;

            if (!epId || (!disciplineId && type !== 'discipline')) {
                console.warn('[Sportabzeichen] Ungültige Daten:', epId, disciplineId);
                return;
            }

            const payload = {
                ep_id: epId,
                discipline_id: disciplineId,
                leistung: leistung
            };

            el.classList.remove('saved', 'error');
            el.classList.add('saving');

            // WICHTIG: Prüfen ob Route und Token existieren
            if (!IServ.routes.sportabzeichen_exam_result_save) {
                console.error('[Sportabzeichen] Route sportabzeichen_exam_result_save fehlt!');
                return;
            }

            fetch(IServ.routes.sportabzeichen_exam_result_save, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': IServ.csrfToken.sportabzeichen_result_save
                },
                body: JSON.stringify(payload)
            })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                el.classList.remove('saving');
                el.classList.add('saved');
                setTimeout(() => el.classList.remove('saved'), 1200);
            })
            .catch(err => {
                console.error('[Sportabzeichen] Fehler beim Speichern:', err);
                el.classList.remove('saving');
                el.classList.add('error');
            });
        }

        function init(context) {
            // Wir suchen nur im aktuellen Kontext (wichtig für AJAX-Navigation)
            const fields = (context || document).querySelectorAll('[data-save]');
            
            if (!fields.length) {
                return;
            }

            console.log('[Sportabzeichen] Autosave aktiviert für', fields.length, 'Felder');

            fields.forEach(el => {
                // Event-Listener sauber binden
                el.addEventListener('change', () => saveChange(el));

                el.addEventListener('keydown', e => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        el.blur(); // Löst 'change' aus
                    }
                });
            });
        }

        // Rückgabe der öffentlichen API
        return {
            init: init
        };
    })();

    // DAS HIER IST DER ENTSCHEIDENDE TEIL:
    // IServ.setup führt die Funktion bei jedem Seitenladen und jedem AJAX-Update aus.
    IServ.setup(function(context) {
        IServ.SportabzeichenAutosave.init(context);
    });

})(jQuery);