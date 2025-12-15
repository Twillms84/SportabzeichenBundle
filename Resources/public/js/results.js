console.log("Sportabzeichen JS geladen!");

document.addEventListener("DOMContentLoaded", () => {

    let saveBtn = document.getElementById("saveAllBtn");
    if (!saveBtn) return;

    saveBtn.addEventListener("click", () => {

        console.log("Speichern geklickt!");

        let entries = [];

        document.querySelectorAll(".result-input").forEach(input => {
            let ep = input.dataset.ep;
            let leistung = input.value;

            let select = input.closest("td").querySelector(".discipline-select");
            let discipline = select.value;

            if (!discipline || leistung === "") return;

            entries.push({
                ep_id: ep,
                discipline_id: discipline,
                leistung: leistung
            });
        });

        fetch(IServ.routes.resolve('sportabzeichen_results_save_many'), {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify(entries)
        })
        .then(r => r.json())
        .then(() => alert("Alle Ergebnisse gespeichert!"));
    });

});
