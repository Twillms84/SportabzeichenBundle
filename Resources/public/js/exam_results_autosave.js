document.addEventListener('DOMContentLoaded', function () {
    const inputs = document.querySelectorAll('[data-save]');
    if (!inputs.length) return;

    inputs.forEach(el => {
        el.addEventListener('change', async function () {
            const epId = this.dataset.epId;
            const type = this.dataset.type;
            const disciplineId = this.dataset.disciplineId || (this.tagName === 'SELECT' ? this.value : null);
            const leistung = type === 'leistung' ? this.value : null;

            if (!epId || (!disciplineId && type !== 'discipline')) return;

            const payload = {
                ep_id: epId,
                discipline_id: disciplineId,
                leistung: leistung
            };

            try {
                const res = await fetch(IServ.routes.sportabzeichen_exam_result_save, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': IServ.csrfToken.sportabzeichen_result_save
                    },
                    body: JSON.stringify(payload)
                });

                if (!res.ok) throw new Error(await res.text());

                this.classList.add('saved');
                setTimeout(() => this.classList.remove('saved'), 800);
            } catch (e) {
                console.error('Fehler beim Speichern:', e);
                this.classList.add('error');
            }
        });
    });
});
