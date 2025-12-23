(function($) {
    const saveChange = function(el) {
        // Wir holen uns die Route und das Token direkt aus dem Formular-Attribut
        const $form = $(el).closest('form');
        const route = $form.data('global-route');
        const token = $form.data('global-token');

        const epId = el.dataset.epId;
        const type = el.dataset.type;
        // Falls es die Leistung ist, brauchen wir die Disziplin-ID
        const disciplineId = el.dataset.disciplineId || (el.tagName === 'SELECT' ? el.value : null);
        const leistung = el.value;

        // Nur senden, wenn wir wissen, für wen (epId) und was (disciplineId)
        if (!epId || !disciplineId || !route) return;

        // Optisches Feedback: Gelb = "Speichert..."
        $(el).css('background-color', '#fff9c4'); 

        fetch(route, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token
            },
            body: JSON.stringify({
                ep_id: epId,
                discipline_id: disciplineId,
                leistung: leistung
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'ok') {
                // Optisches Feedback: Grün = "In DB gespeichert!"
                $(el).css('background-color', '#c8e6c9');
                setTimeout(() => $(el).css('background-color', ''), 800);
            } else {
                throw new Error(data.error);
            }
        })
        .catch(error => {
            // Optisches Feedback: Rot = "Fehler!"
            $(el).css('background-color', '#ffcdd2');
            console.error("Speicherfehler:", error);
        });
    };

    // Events binden
    $(document).on('change', '[data-save]', function() {
        // Falls Dropdown geändert wird, das zugehörige Input-Feld aktualisieren
        if (this.tagName === 'SELECT' && this.dataset.targetInput) {
            const target = document.getElementById(this.dataset.targetInput);
            if (target) {
                target.dataset.disciplineId = this.value;
                // Wenn bereits ein Wert im Feld steht, auch diesen speichern
                if (target.value !== "") saveChange(target);
            }
        }
        saveChange(this);
    });
})(jQuery);