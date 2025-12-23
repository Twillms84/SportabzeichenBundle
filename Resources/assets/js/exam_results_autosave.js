(function($) {
    console.log("--- Sportabzeichen Skript geladen ---");

    const initAutosave = function(context) {
        const fields = (context || document).querySelectorAll('[data-save]');
        console.log("--- Sportabzeichen init aufgerufen für " + fields.length + " Felder ---");

        fields.forEach(el => {
            // Wir binden das Event direkt
            $(el).off('change.autosave').on('change.autosave', function() {
                console.log("--- Speichere Feld:", this.dataset.epId, "Wert:", this.value);
                // Hier kommt später dein fetch() wieder rein
            });
        });
    };

    // IServ Weg
    $(function() {
        if (window.IServ && typeof IServ.setup === 'function') {
            IServ.setup(function(context) {
                initAutosave(context);
            });
        } else {
            // Fallback falls IServ Core nicht vorhanden
            initAutosave();
        }
    });

})(jQuery);