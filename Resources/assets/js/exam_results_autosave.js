
/**
 * PulsR Sportabzeichen Autosave Modul
 * -----------------------------------
 * Speichert Disziplin- und Leistungswerte automatisch beim Verlassen eines Feldes.
 * Läuft über IServ.CoreJS und CSP-konform.
 */

IServ.SportabzeichenAutosave = IServ.register(function() {

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

    function init() {
        const fields = document.querySelectorAll('[data-save]');
        if (!fields.length) {
            console.warn('[Sportabzeichen] Keine Autosave-Felder gefunden.');
            return;
        }

        console.log('[Sportabzeichen] Autosave aktiviert für', fields.length, 'Felder');

        fields.forEach(el => {
            el.addEventListener('change', () => saveChange(el));

            el.addEventListener('keydown', e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    el.blur(); // löst change aus
                }
            });
        });
    }

    // API für IServ
    return {
        init: init
    };

}()); // Ende Modul-Definition

export default IServ.SportabzeichenAutosave;
