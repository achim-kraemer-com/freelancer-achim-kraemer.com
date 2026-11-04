<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the address book of portal.achim-kraemer.com.
 *
 * Only reading, and only what the portal's own API offers (/api/kontakte,
 * token in the header "X-Api-Token"). The two apps have separate databases,
 * so the contacts come over HTTP; nothing of them is stored here. What the
 * user picks becomes a fresh customer via ContactToCustomer.
 *
 * Every failure – portal down, token missing, answer unreadable – leaves
 * here as a PortalError with a sentence the page can show. An admin page
 * that dies with a stack trace because another machine is rebooting would be
 * the worse answer.
 */
final class PortalContacts
{
    /** How long a request may take before the page gives up on it. */
    private const float TIMEOUT = 5.0;
    private const float MAX_DURATION = 10.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $portalApiUrl,
        private readonly string $portalApiToken,
    ) {
    }

    /**
     * Whether address and token are configured at all.
     *
     * Asked before the request, so a fresh installation says "no token yet"
     * instead of running into a 401 from the portal.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->portalApiToken !== '';
    }

    /**
     * The contacts, alphabetically – narrowed down by a search term.
     *
     * The portal searches in name, company, city, note, mail addresses and
     * phone numbers; what it finds is what comes back.
     *
     * @return list<PortalContact>
     *
     * @throws PortalError
     */
    public function search(?string $query = null, int $limit = 100): array
    {
        $parameters = ['limit' => \max(1, $limit)];

        if ($query !== null && \trim($query) !== '') {
            $parameters['q'] = \trim($query);
        }

        $answer = $this->get('/api/kontakte', $parameters);
        $rows   = $answer['contacts'] ?? null;

        if (!\is_array($rows)) {
            throw new PortalError('Das Portal hat keine Kontaktliste geliefert.');
        }

        $contacts = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $contacts[] = PortalContact::fromArray($row);
            }
        }

        return $contacts;
    }

    /**
     * One contact by its id in the portal – or null if the portal does not
     * know it (any more).
     *
     * @throws PortalError
     */
    public function find(int $id): ?PortalContact
    {
        $answer = $this->get('/api/kontakte/'.$id, [], allowNotFound: true);

        if ($answer === []) {
            return null;
        }

        $row = $answer['contact'] ?? null;

        return \is_array($row) ? PortalContact::fromArray($row) : null;
    }

    /**
     * One GET against the portal, decoded.
     *
     * A 404 comes back as an empty array when the caller expects it – that
     * is an answer, not a breakdown. Everything else that is not a 200
     * becomes a PortalError.
     *
     * @param array<string, scalar> $parameters
     *
     * @return array<string, mixed>
     *
     * @throws PortalError
     */
    private function get(string $path, array $parameters = [], bool $allowNotFound = false): array
    {
        if (!$this->isConfigured()) {
            throw new PortalError(
                'Für das Portal fehlen Adresse oder Token. Beides gehört in die .env.local '
                .'(PORTAL_API_URL, PORTAL_API_TOKEN); das Token erzeugt das Portal unter Einstellungen → Profil.',
            );
        }

        try {
            $response = $this->httpClient->request('GET', $this->baseUrl().$path, [
                'headers'      => ['X-Api-Token' => $this->portalApiToken, 'Accept' => 'application/json'],
                'query'        => $parameters,
                'timeout'      => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
            ]);

            $status = $response->getStatusCode();

            if ($status === Response::HTTP_NOT_FOUND && $allowNotFound) {
                return [];
            }

            if ($status === Response::HTTP_UNAUTHORIZED) {
                throw new PortalError('Das Portal hat das Token abgelehnt. Im Portal unter Einstellungen → Profil ein neues erzeugen und in die .env.local eintragen.');
            }

            if ($status !== Response::HTTP_OK) {
                throw new PortalError(\sprintf('Das Portal hat mit Status %d geantwortet.', $status));
            }

            $content = $response->getContent();
        } catch (HttpClientException $e) {
            throw new PortalError('Das Portal ist nicht erreichbar: '.$e->getMessage(), previous: $e);
        }

        try {
            $decoded = \json_decode($content, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PortalError('Die Antwort des Portals war kein gültiges JSON.', previous: $e);
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /** The configured address without a trailing slash. */
    private function baseUrl(): string
    {
        return \rtrim(\trim($this->portalApiUrl), '/');
    }
}
