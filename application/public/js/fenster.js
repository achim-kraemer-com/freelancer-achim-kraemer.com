/**
 * Was die Fenster hinter Lupe und „A" in der Navigation gemeinsam haben
 * (templates/_admin_fenster.html.twig): öffnen, schließen und mit den
 * Pfeiltasten durch die Links darin gehen. Was sie tun, steht in suche.js
 * und assistent.js. Aus dem Portal übernommen (dort public/js/fenster.js).
 *
 *   var fenster = adminFenster(dialog, beimOeffnen);
 *   fenster.oeffnen();
 *
 * beimOeffnen läuft, wenn das Fenster aufgeht – etwa um den Stand zu laden.
 */
window.adminFenster = function (dialog, beimOeffnen) {
    'use strict';

    var feld    = dialog.querySelector('.fenster-feld input:not([type="hidden"]), .fenster-feld textarea');
    var ausgabe = dialog.querySelector('.fenster-ausgabe');

    function oeffnen() {
        if (!dialog.open) {
            dialog.showModal();

            if (beimOeffnen) {
                beimOeffnen();
            }
        }

        feld.focus();
        feld.select();
    }

    // Ein Klick auf den abgedunkelten Rand schließt das Fenster.
    dialog.addEventListener('click', function (ereignis) {
        if (ereignis.target === dialog) {
            dialog.close();
        }
    });

    dialog.addEventListener('keydown', function (ereignis) {
        // Im Suchfeld leerte Escape sonst erst das Feld, und erst ein zweites
        // schlösse das Fenster. Die Eingabe bleibt so fürs nächste Öffnen stehen.
        if (ereignis.key === 'Escape') {
            ereignis.preventDefault();
            dialog.close();

            return;
        }

        if (ereignis.key !== 'ArrowDown' && ereignis.key !== 'ArrowUp') {
            return;
        }

        // In einem mehrzeiligen Feld gehören die Pfeiltasten dem Text.
        if (document.activeElement === feld && feld.tagName === 'TEXTAREA') {
            return;
        }

        var liste = Array.prototype.slice.call(ausgabe.querySelectorAll('a[href]'));
        var platz = liste.indexOf(document.activeElement);

        if (liste.length === 0 || (platz === -1 && document.activeElement !== feld)) {
            return;
        }

        ereignis.preventDefault();

        if (ereignis.key === 'ArrowDown') {
            liste[Math.min(platz + 1, liste.length - 1)].focus();
        } else if (platz <= 0) {
            feld.focus();
        } else {
            liste[platz - 1].focus();
        }
    });

    return { oeffnen: oeffnen, feld: feld, ausgabe: ausgabe };
};
