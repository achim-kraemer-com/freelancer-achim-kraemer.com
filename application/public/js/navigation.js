/* Die untere Navigationsleiste auf dem Telefon (templates/_mobile_tabs.html.twig).
   „Mehr" ist ein <details>; der Browser klappt es auf und zu, ohne dass hier
   etwas nötig wäre. Dieses Skript macht nur das, was der Browser nicht tut:
   Es schließt das Menü wieder, wenn man daneben tippt oder Esc drückt. */
(function () {
    'use strict';

    function schliessen(ausser) {
        document.querySelectorAll('.mehr-menue[open]').forEach(function (menue) {
            if (!ausser || !menue.contains(ausser)) {
                menue.open = false;
            }
        });
    }

    document.addEventListener('click', function (ereignis) {
        schliessen(ereignis.target);
    });

    document.addEventListener('keydown', function (ereignis) {
        if (ereignis.key === 'Escape') {
            schliessen(null);
        }
    });
})();
