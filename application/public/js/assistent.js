/**
 * Das Assistenten-Fenster hinter dem „A" in der Navigation. Aus dem Portal
 * übernommen (dort public/js/assistent.js).
 *
 * Beim Öffnen holt es den Stand des Gesprächs (/admin/assistent?teil=1) –
 * Verlauf und vorbereitete Änderungen liegen in der Sitzung und sollen auf
 * jeder Seite dieselben sein. Die Frage, „Übernehmen", „Verwerfen" und
 * „Verlauf leeren" gehen im Hintergrund an dieselben Adressen wie auf der
 * Seite /admin/assistent, nur mit `teil=1`; zurück kommt jedes Mal das ganze
 * Gespräch als HTML und ersetzt das alte. Links darin – auf gefundene und
 * eben angelegte Einträge – sind gewöhnliche Links.
 *
 * Eine Frage geht mit `strom=1` hinaus: Die Antwort kommt Zeile für Zeile
 * (NDJSON), während das Modell schreibt – Textstücke, und was es gerade
 * nachsieht. Am Ende steht ein Schlüssel, mit dem
 * /admin/assistent/abschliessen das Ergebnis in die Sitzung legt und das
 * fertige Gespräch liefert. Kommt statt des Stroms HTML zurück (abgelaufenes
 * Formular, kein Modell), wird es eingesetzt wie jede andere Antwort.
 *
 * Enter sendet, Umschalt+Enter macht eine neue Zeile – wie im Chat. Das gilt
 * auch im Feld der Seite /admin/assistent.
 */
(function () {
    'use strict';

    function mitTeil(adresse) {
        return adresse + (adresse.indexOf('?') === -1 ? '?' : '&') + 'teil=1';
    }

    function enterSendet(feld) {
        feld.addEventListener('keydown', function (ereignis) {
            if (ereignis.key === 'Enter' && !ereignis.shiftKey && !ereignis.isComposing) {
                ereignis.preventDefault();

                if (feld.value.trim() !== '') {
                    feld.form.requestSubmit();
                }
            }
        });
    }

    // Die Seite /admin/assistent: abgeschickt wird dort klassisch, mit Neuladen.
    var seitenFeld = document.getElementById('assistant-text');

    if (seitenFeld) {
        enterSendet(seitenFeld);
        seitenFeld.form.addEventListener('submit', function () {
            seitenFeld.form.classList.add('is-sendet');
        });
    }

    var dialog = document.getElementById('assistent-dialog');

    if (!dialog || typeof dialog.showModal !== 'function' || !window.adminFenster) {
        return;
    }

    var fenster = window.adminFenster(dialog, laden);
    var form    = dialog.querySelector('form.fenster-feld');
    var feld    = fenster.feld;
    var ausgabe = fenster.ausgabe;
    var laufend = null;

    function laden() {
        anfragen(ausgabe.getAttribute('data-gespraech'), null, null);
    }

    /**
     * Holt das Gespräch und setzt es ein. Solange gewartet wird, steht
     * `warten` über dem bisherigen Gespräch. Das Versprechen sagt, ob es
     * geklappt hat.
     */
    function anfragen(adresse, daten, warten) {
        if (laufend) {
            laufend.abort();
        }

        var meins = laufend = new AbortController();
        var hinweis = null;

        if (warten) {
            hinweis = document.createElement('p');
            hinweis.className = 'fenster-laedt';
            hinweis.textContent = warten;
            ausgabe.insertBefore(hinweis, ausgabe.firstChild);
            dialog.classList.add('is-beschaeftigt');
        }

        return fetch(adresse, {
            method: daten ? 'POST' : 'GET',
            body: daten,
            signal: meins.signal,
            credentials: 'same-origin',
        })
            .then(function (antwort) {
                // Nach Ablauf der Anmeldung käme hier die Anmeldeseite an.
                if (!antwort.ok || antwort.redirected) {
                    throw new Error('Status ' + antwort.status);
                }

                return antwort.text();
            })
            .then(function (html) {
                ausgabe.innerHTML = html;

                return true;
            })
            .catch(function (fehler) {
                if (fehler.name === 'AbortError') {
                    return false;
                }

                if (hinweis && hinweis.parentNode) {
                    hinweis.parentNode.removeChild(hinweis);
                }

                var meldung = document.createElement('div');
                meldung.className = 'alert alert-danger';
                meldung.textContent = 'Der Assistent ist gerade nicht erreichbar. Bitte noch einmal versuchen.';
                ausgabe.insertBefore(meldung, ausgabe.firstChild);

                return false;
            })
            .finally(function () {
                // Eine abgebrochene Anfrage räumt nicht ab, was die neue zeigt.
                if (laufend === meins) {
                    dialog.classList.remove('is-beschaeftigt');
                }
            });
    }

    /**
     * Die Antwort entsteht oben im Gespräch, über der Frage – dieselbe
     * Reihenfolge wie im fertigen Verlauf (neueste zuerst). Text wird nur als
     * Text eingesetzt; Links und Formatierung bringt erst das fertige Gespräch.
     */
    function liveBlock(frage) {
        var block = document.createElement('div');
        block.className = 'chat assistent-live';
        block.innerHTML =
            '<div class="chat-row is-assistant"><div class="chat-bubble">'
            + '<p class="fenster-laedt assistent-taetigkeit">Denkt nach …</p>'
            + '<div class="assistent-strom"></div></div></div>'
            + '<div class="chat-row is-user"><div class="chat-bubble"></div></div>';
        block.querySelector('.is-user .chat-bubble').textContent = frage;

        var gespraech = ausgabe.querySelector('.assistent-gespraech') || ausgabe;
        var vorher    = gespraech.querySelector('.chat');
        gespraech.insertBefore(block, vorher || null);

        return {
            block: block,
            taetigkeit: block.querySelector('.assistent-taetigkeit'),
            strom: block.querySelector('.assistent-strom'),
        };
    }

    /** Liest den Strom Zeile für Zeile und ruft je Zeile `jeZeile(objekt)`. */
    function zeilenLesen(antwort, jeZeile) {
        var leser   = antwort.body.getReader();
        var decoder = new TextDecoder();
        var puffer  = '';

        function weiter() {
            return leser.read().then(function (teil) {
                puffer += decoder.decode(teil.value || new Uint8Array(), { stream: !teil.done });

                var zeilen = puffer.split('\n');
                puffer = teil.done ? '' : zeilen.pop();

                zeilen.forEach(function (zeile) {
                    if (zeile.trim() !== '') {
                        jeZeile(JSON.parse(zeile));
                    }
                });

                return teil.done ? null : weiter();
            });
        }

        return weiter();
    }

    function fragen(frage) {
        if (laufend) {
            laufend.abort();
        }

        var meins = laufend = new AbortController();
        var live  = liveBlock(frage);
        var daten = new FormData(form);
        var schluessel = null;
        var fehler = null;

        dialog.classList.add('is-beschaeftigt');

        return fetch(mitTeil(form.getAttribute('action')) + '&strom=1', {
            method: 'POST',
            body: daten,
            signal: meins.signal,
            credentials: 'same-origin',
        })
            .then(function (antwort) {
                if (!antwort.ok || antwort.redirected) {
                    throw new Error('Status ' + antwort.status);
                }

                // Kein Strom: Der Server hat gleich das Gespräch geschickt.
                if ((antwort.headers.get('Content-Type') || '').indexOf('ndjson') === -1) {
                    return antwort.text().then(function (html) {
                        ausgabe.innerHTML = html;

                        return true;
                    });
                }

                return zeilenLesen(antwort, function (zeile) {
                    if (zeile.art === 'text') {
                        live.taetigkeit.hidden = true;
                        live.strom.textContent += zeile.inhalt;
                    } else if (zeile.art === 'werkzeug') {
                        live.taetigkeit.hidden = false;
                        live.taetigkeit.textContent = zeile.inhalt + ' …';
                    } else if (zeile.art === 'runde') {
                        // Was vor den Werkzeugen stand, war ein Zwischenstand.
                        live.strom.textContent = '';
                        live.taetigkeit.hidden = false;
                        live.taetigkeit.textContent = 'Denkt nach …';
                    } else if (zeile.art === 'fehler') {
                        fehler = zeile.inhalt;
                    } else if (zeile.art === 'fertig') {
                        schluessel = zeile.inhalt;
                    }
                }).then(function () {
                    if (fehler !== null || schluessel === null) {
                        throw new Error(fehler || 'Strom ohne Abschluss');
                    }

                    var abschluss = new FormData();
                    abschluss.append('_token', daten.get('_token'));
                    abschluss.append('schluessel', schluessel);

                    return fetch(form.getAttribute('data-abschliessen'), {
                        method: 'POST',
                        body: abschluss,
                        signal: meins.signal,
                        credentials: 'same-origin',
                    }).then(function (antwort) {
                        if (!antwort.ok || antwort.redirected) {
                            throw new Error('Status ' + antwort.status);
                        }

                        return antwort.text();
                    }).then(function (html) {
                        ausgabe.innerHTML = html;

                        return true;
                    });
                });
            })
            .catch(function (grund) {
                if (grund.name === 'AbortError') {
                    return false;
                }

                if (live.block.parentNode) {
                    live.block.parentNode.removeChild(live.block);
                }

                var meldung = document.createElement('div');
                meldung.className = 'alert alert-danger';
                meldung.textContent = fehler || 'Der Assistent ist gerade nicht erreichbar. Bitte noch einmal versuchen.';
                ausgabe.insertBefore(meldung, ausgabe.firstChild);

                return false;
            })
            .finally(function () {
                if (laufend === meins) {
                    dialog.classList.remove('is-beschaeftigt');
                }
            });
    }

    // Das Feld wächst mit dem Text, bis etwa sechs Zeilen; erst darüber
    // hinaus bekommt es einen Rollbalken.
    function groesse() {
        // Leer zählt der Platzhalter mit, und der darf umbrechen – also eine Zeile.
        if (feld.value === '') {
            feld.style.height = '';
            feld.style.overflowY = '';

            return;
        }

        feld.style.height = 'auto';
        feld.style.height = Math.min(feld.scrollHeight, 160) + 'px';
        feld.style.overflowY = feld.scrollHeight > 160 ? 'auto' : 'hidden';
    }

    document.addEventListener('click', function (ereignis) {
        if (ereignis.target.closest('[data-assistent-oeffnen]')) {
            ereignis.preventDefault();
            fenster.oeffnen();
        }
    });

    enterSendet(feld);
    feld.addEventListener('input', groesse);

    form.addEventListener('submit', function (ereignis) {
        ereignis.preventDefault();

        var frage = feld.value.trim();

        if (frage === '' || dialog.classList.contains('is-beschaeftigt')) {
            return;
        }

        fragen(frage)
            .then(function (geklappt) {
                // Erst jetzt leeren: Geht die Anfrage schief, ist die Frage nicht verloren.
                if (geklappt) {
                    feld.value = '';
                    groesse();
                    feld.focus();
                }
            });
    });

    ausgabe.addEventListener('submit', function (ereignis) {
        var aktion = ereignis.target.closest('form[data-assistent-aktion]');

        if (!aktion) {
            return;
        }

        ereignis.preventDefault();

        var uebernehmen = aktion.querySelector('.btn-primary') !== null;

        anfragen(mitTeil(aktion.getAttribute('action')), new FormData(aktion), uebernehmen ? 'Wird übernommen …' : null);
    });
}());
