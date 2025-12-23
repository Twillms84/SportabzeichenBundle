(function($) {
    console.log("[Sportabzeichen] Skript geladen");

    const saveChange = function(el) {
        // Erst im Moment des Speicherns prüfen wir, ob die Daten da sind
        if (!window.IServ || !IServ.routes || !IServ.routes.sportabzeichen_exam_result_save) {
            console.error("[Sportabzeichen] Abbruch: IServ.routes nicht definiert. Bitte Seite neu laden.");
            $(el).css('background-color', '#ffcdd2');
            return;
        }

        const epId = el.dataset.epId;
        const type = el.dataset.type;
        const disciplineId = el.dataset.disciplineId || (el.tagName === 'SELECT' ? el.value : null);
        const leistung = el.value;

        if (!epId || !disciplineId) return;

        $(el).css('background-color', '#fff9c4'); 

        const payload = {
            ep_id: epId,
            discipline_id: disciplineId,
            leistung: leistung,
            type: type
        };

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
            if (!res.ok) throw new Error('Server Fehler');
            return res.json();
        })
        .then(data => {
            $(el).css('background-color', '#c8e6c9');
            setTimeout(() => $(el).css('background-color', ''), 1000);
        })
        .catch(err => {
            $(el).css('background-color', '#ffcdd2');
            console.error("[Sportabzeichen] Fehler:", err);
        });
    };

    const initAutosave = function(context) {
        const fields = (context || document).querySelectorAll('[data-save]');
        
        fields.forEach(el => {
            // Event für Änderungen (Input & Select)
            $(el).off('change.autosave').on('change.autosave', function() {
                // Wenn es ein Select ist, aktualisiere das zugehörige Input-Feld
                if (this.tagName === 'SELECT' && this.dataset.targetInput) {
                    const target = document.getElementById(this.dataset.targetInput);
                    if (target) target.dataset.disciplineId = this.value;
                }
                saveChange(this);
            });
        });
    };

    // Warten bis alles bereit ist
    $(document).ready(function() {
        if (window.IServ && typeof IServ.setup === 'function') {
            IServ.setup(function(context) {
                initAutosave(context);
            });
        } else {
            initAutosave();
        }
    });

})(jQuery);