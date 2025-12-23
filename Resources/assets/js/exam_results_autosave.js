(function($) {
    console.log("[Sportabzeichen] Skript geladen");

    const saveChange = function(el) {
        const epId = el.dataset.epId;
        const type = el.dataset.type;
        const disciplineId = el.dataset.disciplineId || (el.tagName === 'SELECT' ? el.value : null);
        const leistung = el.value;

        if (!epId) return;

        // Visuelles Feedback: Feld gelb markieren während des Speicherns
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
            // Erfolg: Feld kurz grün, dann normal
            $(el).css('background-color', '#c8e6c9');
            setTimeout(() => $(el).css('background-color', ''), 1000);
            console.log("[Sportabzeichen] Gespeichert:", epId);
        })
        .catch(err => {
            // Fehler: Feld rot
            $(el).css('background-color', '#ffcdd2');
            console.error("[Sportabzeichen] Fehler:", err);
        });
    };

    const initAutosave = function(context) {
        const fields = (context || document).querySelectorAll('[data-save]');
        console.log("[Sportabzeichen] Init für " + fields.length + " Felder");

        fields.forEach(el => {
            $(el).off('change.autosave').on('change.autosave', function() {
                saveChange(this);
            });
        });
    };

    $(function() {
        if (window.IServ && typeof IServ.setup === 'function') {
            IServ.setup(function(context) {
                initAutosave(context);
            });
        } else {
            initAutosave();
        }
    });

})(jQuery);