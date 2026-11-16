/**
 * Das Such-Fenster hinter der Lupe in der Navigation. Aus dem Portal
 * übernommen (dort public/js/suche.js).
 *
 * Die Lupe (`data-suche-oeffnen`) und ⌘K bzw. Strg+K öffnen den Dialog aus
 * templates/_admin_fenster.html.twig. Abgeschickt wird erst mit Enter, nicht
 * bei jedem Tastendruck: Jede Suche fragt einmal die KI, und das kostet Zeit
 * und Geld. Die Ergebnisse holt die Seite /admin/suche mit `teil=1` als
 * fertiges HTML; ein Klick auf einen Treffer ist ein gewöhnlicher Link.
 * Öffnen, Schließen und Pfeiltasten: fenster.js.
 *
 * Ohne JavaScript führt die Lupe einfach auf die Seite /admin/suche.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('suche-dialog');

    if (!dialog || typeof dialog.showModal !== 'function' || !window.adminFenster) {
        return;
    }

    var fenster = window.adminFenster(dialog);
    var form    = dialog.querySelector('form');
    var laufend = null;

    function suchen() {
        var q = fenster.feld.value.trim();

        if (q === '') {
            return;
        }

        // Eine neue Suche ersetzt die alte; deren Antwort käme sonst
        // womöglich später an und überschriebe die neue.
        if (laufend) {
            laufend.abort();
        }

        var meins = laufend = new AbortController();
        fenster.ausgabe.innerHTML = '<p class="fenster-laedt">Die KI durchsucht alle Bereiche …</p>';
        dialog.classList.add('is-beschaeftigt');

        var adresse = form.getAttribute('action') + '?teil=1&q=' + encodeURIComponent(q);

        fetch(adresse, { signal: meins.signal, credentials: 'same-origin' })
            .then(function (antwort) {
                // Nach Ablauf der Anmeldung käme hier die Anmeldeseite an.
                if (!antwort.ok || antwort.redirected) {
                    throw new Error('Status ' + antwort.status);
                }

                return antwort.text();
            })
            .then(function (html) {
                fenster.ausgabe.innerHTML = html;
            })
            .catch(function (fehler) {
                if (fehler.name !== 'AbortError') {
                    fenster.ausgabe.innerHTML = '<p class="fenster-hinweis">Die Suche ist fehlgeschlagen. Bitte noch einmal versuchen.</p>';
                }
            })
            .finally(function () {
                if (laufend === meins) {
                    dialog.classList.remove('is-beschaeftigt');
                }
            });
    }

    document.addEventListener('click', function (ereignis) {
        if (ereignis.target.closest('[data-suche-oeffnen]')) {
            ereignis.preventDefault();
            fenster.oeffnen();
        }
    });

    document.addEventListener('keydown', function (ereignis) {
        if ((ereignis.metaKey || ereignis.ctrlKey) && !ereignis.altKey && ereignis.key.toLowerCase() === 'k') {
            ereignis.preventDefault();
            fenster.oeffnen();
        }
    });

    form.addEventListener('submit', function (ereignis) {
        ereignis.preventDefault();
        suchen();
    });
}());
