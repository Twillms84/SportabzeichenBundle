/**
 * PulsR Sportabzeichen Autosave Modul
 */
(function($) {
    // 1. Modul-Logik definieren
    const SportabzeichenAutosave = (function() {
        function saveChange(el) {
            const epId = el.dataset.epId;
            const type = el.dataset.type;
            const disciplineId = el.dataset.disciplineId || (el.tagName === 'SELECT' ? el.value : null);
            const leistung = type === 'leistung' ? el.value : null;

            if (!epId || (!disciplineId && type !== 'discipline')) {
                return;
            }

            // Sicherstellen, dass IServ-Objekte vorhanden sind
            if (!window.IServ || !IServ.routes || !IServ.routes.sportabzeichen_exam_result_save) {
                console.error('[Sportabzeichen] IServ-Konfiguration fehlt (Route/Token)');
                return;
            }

            const payload = {
                ep_id: epId,
                discipline_id: disciplineId,
                leistung: leistung
            };

            el.classList.remove('saved', 'error');
            el.classList.add('saving');

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

        return {
            init: function(context) {
                const fields = (context || document).querySelectorAll('[data-save]');
                if (!fields.length) return;

                console.log('[Sportabzeichen] Autosave aktiviert für', fields.length, 'Felder');

                fields.forEach(el => {
                    el.addEventListener('change', () => saveChange(el));
                    el.addEventListener('keydown', e => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            el.blur();
                        }
                    });
                });
            }
        };
    })();

    // 2. Sicher im IServ-Namespace ablegen
    window.IServ = window.IServ || {};
    IServ.SportabzeichenAutosave = SportabzeichenAutosave;

    // 3. Warten, bis IServ.setup verfügbar ist
    const bootstrap = function() {
        if (typeof IServ !== 'undefined' && typeof IServ.setup === 'function') {
            IServ.setup(function(context) {
                IServ.SportabzeichenAutosave.init(context);
            });
        } else {
            // Falls IServ noch nicht ganz bereit ist, kurz warten (Intervall)
            let attempts = 0;
            const interval = setInterval(function() {
                attempts++;
                if (typeof IServ !== 'undefined' && typeof IServ.setup === 'function') {
                    clearInterval(interval);
                    IServ.setup(function(context) {
                        IServ.SportabzeichenAutosave.init(context);
                    });
                }
                if (attempts > 20) clearInterval(interval); // Timeout nach 2 Sek
            }, 100);
        }
    };

    // Starten sobald DOM bereit
    $(bootstrap);

})(jQuery);