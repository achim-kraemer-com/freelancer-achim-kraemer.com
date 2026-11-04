<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The portal's address book could not be read: it is unreachable, the token
 * is missing or wrong, or the answer was not what it should be.
 *
 * One exception for all three, because the page does the same thing with
 * each: it says what is wrong instead of showing a contact list. The message
 * is meant for the user and is therefore in German.
 */
final class PortalError extends \RuntimeException
{
}
