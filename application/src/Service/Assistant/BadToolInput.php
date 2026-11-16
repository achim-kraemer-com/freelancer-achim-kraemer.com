<?php

declare(strict_types=1);

namespace App\Service\Assistant;

/**
 * Ein Werkzeugaufruf des Modells ist nicht ausführbar – etwa weil ein Datum
 * unlesbar ist oder eine ID zu keinem eigenen Eintrag gehört.
 *
 * Die Meldung geht als Werkzeugergebnis zurück an das Modell, damit es die
 * Angabe berichtigen kann, statt den ganzen Durchlauf abzubrechen. Sie wird
 * also gelesen und sollte erklären, was zu tun ist.
 */
final class BadToolInput extends \RuntimeException
{
}
