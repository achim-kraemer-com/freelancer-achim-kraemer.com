<?php

declare(strict_types=1);

namespace App\Service\Assistant;

/**
 * Der Assistent konnte nicht antworten – kein Schlüssel hinterlegt, Dienst
 * überlastet oder nicht erreichbar. Die Meldung ist für den Benutzer gedacht
 * und wird in der Oberfläche angezeigt.
 */
final class AssistantUnavailable extends \RuntimeException
{
}
