/**
 * Der Editor für HTML-Signaturen im Signaturformular.
 *
 * Bei „HTML“ tritt ein bearbeitbarer Kasten an die Stelle der Textarea. Was
 * man dort einfügt, bleibt formatiert – auch eine Signatur aus dem
 * Signatur-Generator, dessen „Signatur kopieren“ nur HTML in die
 * Zwischenablage legt und in einer Textarea gar nicht ankäme. Ein Knopf
 * schaltet auf den Quelltext um, wie man es aus Spark kennt.
 *
 * Die Textarea bleibt das Feld, das abgeschickt wird: Der Editor schreibt
 * bei jeder Änderung hinein. Bleibt das Skript aus, steht sie schlicht offen
 * und nimmt den Quelltext entgegen.
 */
(function () {
    'use strict';

    var block = document.querySelector('[data-signatur-editor]');

    if (!block) {
        return;
    }

    var editor    = block.querySelector('[data-editor]');
    var werkzeuge = block.querySelector('[data-werkzeuge]');
    var quellKnopf = block.querySelector('[data-quelltext]');
    var textarea  = block.querySelector('textarea');
    var zeile     = textarea.closest('.form-row');
    var label     = zeile ? zeile.querySelector('label') : null;
    var formular  = block.closest('form');
    var arten     = formular.querySelectorAll('input[type="radio"][name$="[format]"]');

    var quelltext = false;

    function art() {
        for (var i = 0; i < arten.length; i++) {
            if (arten[i].checked) {
                return arten[i].value;
            }
        }

        return 'text';
    }

    function html() {
        return art() === 'html';
    }

    function synchronisieren() {
        textarea.value = editor.innerHTML;
    }

    function anzeigen() {
        var mitEditor = html() && !quelltext;

        werkzeuge.hidden = !html();
        editor.hidden    = !mitEditor;

        if (zeile) {
            zeile.hidden = mitEditor;
        }

        if (label) {
            label.textContent = html() ? 'Quelltext' : 'Text';
        }

        quellKnopf.setAttribute('aria-pressed', quelltext ? 'true' : 'false');
    }

    // --- Art wechseln -------------------------------------------------------

    arten.forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (html()) {
                // Aus Text wird HTML: Zeilen bleiben Zeilen.
                editor.innerText = textarea.value;
                synchronisieren();
            } else {
                // Aus HTML wird Text: Was übrig bleibt, ist der sichtbare Text.
                // innerText kennt Zeilenumbrüche nur an einem sichtbaren Element.
                editor.innerHTML = textarea.value;
                editor.hidden    = false;
                textarea.value   = editor.innerText.trim();
            }

            quelltext = false;
            anzeigen();
        });
    });

    quellKnopf.addEventListener('click', function () {
        if (quelltext) {
            editor.innerHTML = textarea.value;
        } else {
            synchronisieren();
        }

        quelltext = !quelltext;
        anzeigen();
        (quelltext ? textarea : editor).focus();
    });

    editor.addEventListener('input', synchronisieren);

    formular.addEventListener('submit', function () {
        if (html() && !quelltext) {
            synchronisieren();
        }
    });

    // --- Werkzeuge ----------------------------------------------------------

    werkzeuge.querySelectorAll('[data-befehl]').forEach(function (knopf) {
        // Der Klick darf die Auswahl im Editor nicht verlieren.
        knopf.addEventListener('mousedown', function (ereignis) {
            ereignis.preventDefault();
        });

        knopf.addEventListener('click', function () {
            var befehl = knopf.dataset.befehl;

            editor.focus();

            if (befehl === 'link') {
                var ziel = window.prompt('Adresse des Links', 'https://');

                if (ziel && ziel !== 'https://') {
                    document.execCommand('createLink', false, ziel);
                }
            } else if (befehl === 'bild') {
                var bild = window.prompt('Adresse des Bildes', 'https://');

                if (bild && bild !== 'https://') {
                    document.execCommand('insertImage', false, bild);
                }
            } else {
                document.execCommand(befehl, false, null);
            }

            synchronisieren();
        });
    });

    // --- Einfügen -----------------------------------------------------------

    /**
     * Fremdes HTML vor dem Einfügen entschärfen: Skripte, Rahmen und
     * Ereignis-Attribute haben in einer Signatur nichts verloren. Formatierung,
     * Tabellen, Links und Bilder bleiben, wie sie sind.
     */
    function bereinigen(roh) {
        var dokument = new DOMParser().parseFromString(roh, 'text/html');

        dokument.querySelectorAll('script, style, iframe, object, embed, link, meta, title, form').forEach(function (el) {
            el.remove();
        });

        dokument.body.querySelectorAll('*').forEach(function (el) {
            Array.prototype.slice.call(el.attributes).forEach(function (attribut) {
                var name = attribut.name.toLowerCase();

                if (name.indexOf('on') === 0 || (name === 'href' && /^\s*javascript:/i.test(attribut.value))) {
                    el.removeAttribute(attribut.name);
                }
            });
        });

        // Chrome umrahmt kopierte Seitenteile mit StartFragment/EndFragment.
        var laeufer = dokument.createTreeWalker(dokument.body, NodeFilter.SHOW_COMMENT);
        var kommentare = [];

        while (laeufer.nextNode()) {
            kommentare.push(laeufer.currentNode);
        }

        kommentare.forEach(function (kommentar) {
            kommentar.remove();
        });

        return dokument.body.innerHTML.trim();
    }

    function einfuegen(inhalt) {
        editor.focus();

        var auswahl = window.getSelection();

        // Liegt die Auswahl nicht im Editor, kommt der Inhalt ans Ende.
        if (!auswahl.rangeCount || !editor.contains(auswahl.anchorNode)) {
            var bereich = document.createRange();
            bereich.selectNodeContents(editor);
            bereich.collapse(false);
            auswahl.removeAllRanges();
            auswahl.addRange(bereich);
        }

        if (!document.execCommand('insertHTML', false, inhalt)) {
            var range = auswahl.getRangeAt(0);
            range.deleteContents();
            range.insertNode(range.createContextualFragment(inhalt));
        }

        synchronisieren();
    }

    editor.addEventListener('paste', function (ereignis) {
        var daten = ereignis.clipboardData;

        if (!daten) {
            return;
        }

        // Ein Bild als Datei – Logo, Screenshot – kommt als eingebettetes Bild
        // hinein; beim Versand wird daraus ein Anhang mit cid:-Verweis.
        var bilder = Array.prototype.slice.call(daten.files || []).filter(function (datei) {
            return datei.type.indexOf('image/') === 0;
        });

        if (bilder.length > 0) {
            ereignis.preventDefault();

            bilder.forEach(function (datei) {
                var leser = new FileReader();

                leser.onload = function () {
                    einfuegen('<img src="' + leser.result + '" alt="">');
                };

                leser.readAsDataURL(datei);
            });

            return;
        }

        var roh = daten.getData('text/html');

        if (roh) {
            ereignis.preventDefault();
            einfuegen(bereinigen(roh));
        }

        // Reiner Text: Der Browser fügt ihn selbst ein.
    });

    // --- Start --------------------------------------------------------------

    if (html()) {
        editor.innerHTML = textarea.value;
    }

    anzeigen();
})();
