(function($) {
    console.log("[Sportabzeichen] Skript-Datei wurde vom Browser gelesen.");

    const saveChange = function(el) {
        const form = document.getElementById('autosave-form');
        if (!form) {
            console.error("[Sportabzeichen] Fehler: Formular 'autosave-form' nicht gefunden!");
            return;
        }

        const route = form.dataset.globalRoute;
        const token = form.dataset.globalToken;
        const epId = el.dataset.epId;
        const disciplineId = el.dataset.disciplineId || (el.tagName === 'SELECT' ? el.value : null);
        const leistung = el.value;

        console.log(`[Sportabzeichen] Sende Daten: EP=${epId}, Disc=${disciplineId}, Wert=${leistung}`);

        if (!epId || !disciplineId || !route) {
            console.warn("[Sportabzeichen] Abbruch: Fehlende Daten für den Versand.");
            return;
        }

        $(el).css('background-color', '#fff9c4'); 

        fetch(route, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': token
            },
            body: JSON.stringify({
                ep_id: epId,
                discipline_id: disciplineId,
                leistung: leistung
            })
        })
        .then(res => {
            if (!res.ok) throw new Error('Server antwortete mit Fehler ' + res.status);
            return res.json();
        })
        .then(data => {
            console.log("[Sportabzeichen] Erfolg: In DB gespeichert.");
            $(el).css('background-color', '#c8e6c9');
            setTimeout(() => $(el).css('background-color', ''), 800);
        })
        .catch(err => {
            $(el).css('background-color', '#ffcdd2');
            console.error("[Sportabzeichen] AJAX Fehler:", err);
        });
    };

    const initAutosave = function(context) {
        const root = context || document;
        const fields = root.querySelectorAll('[data-save]');
        
        console.log(`[Sportabzeichen] Initialisiere ${fields.length} Felder.`);

        fields.forEach(el => {
            $(el).off('change.autosave').on('change.autosave', function() {
                if (this.tagName === 'SELECT' && this.dataset.targetInput) {
                    const target = document.getElementById(this.dataset.targetInput);
                    if (target) {
                        target.dataset.disciplineId = this.value;
                    }
                }
                saveChange(this);
            });
        });
    };

    // Wir probieren mehrere Wege, um sicherzugehen, dass es startet
    $(document).ready(function() {
        console.log("[Sportabzeichen] Document Ready");
        initAutosave();
    });

    // IServ Pjax Support
    if (window.IServ && typeof IServ.setup === 'function') {
        IServ.setup(function(context) {
            console.log("[Sportabzeichen] IServ.setup aufgerufen");
            initAutosave(context);
        });
    }

})(jQuery);