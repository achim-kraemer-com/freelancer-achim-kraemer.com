<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PortalContacts;
use App\Service\PortalError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * What comes back from the portal's address book – and what happens when it
 * does not.
 *
 * The portal is answered by a MockHttpClient here: the point is the request
 * that goes out (token, path, search term) and that every kind of failure
 * arrives as one PortalError with a readable sentence, so an admin page
 * never dies because another machine is down.
 */
final class PortalContactsTest extends TestCase
{
    public function testTheListComesBackAsContacts(): void
    {
        $client = new MockHttpClient(new MockResponse((string) \json_encode([
            'contacts' => [
                [
                    'id'           => 7,
                    'name'         => 'Max Muster',
                    'organisation' => 'Musterfirma GmbH',
                    'emails'       => ['max@example.com'],
                    'phones'       => ['0170 1234567'],
                    'street'       => 'Musterweg 7',
                    'postalCode'   => '49477',
                    'city'         => 'Ibbenbüren',
                    'country'      => 'Deutschland',
                    'website'      => 'https://www.musterfirma.de',
                    'note'         => 'Dächer.',
                    'tags'         => ['Geschäftlich'],
                ],
            ],
            'count' => 1,
        ])));

        $contacts = $this->portal($client)->search();

        self::assertCount(1, $contacts);
        self::assertSame(7, $contacts[0]->id);
        self::assertSame('Max Muster', $contacts[0]->name);
        self::assertSame('Musterfirma GmbH (Max Muster)', $contacts[0]->label());
        self::assertSame('max@example.com', $contacts[0]->primaryEmail());
        self::assertSame('Musterweg 7', $contacts[0]->street);
        self::assertSame('49477', $contacts[0]->postalCode);
        self::assertSame('Ibbenbüren', $contacts[0]->city);
        self::assertSame(['Geschäftlich'], $contacts[0]->tags);
    }

    public function testTokenAndSearchTermGoAlong(): void
    {
        $gesehen = [];
        $client  = new MockHttpClient(function (string $method, string $url, array $options) use (&$gesehen): MockResponse {
            $gesehen = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"contacts":[],"count":0}');
        });

        $this->portal($client)->search('  Muster  ', limit: 25);

        self::assertSame('GET', $gesehen['method']);
        self::assertStringStartsWith('https://portal.example/api/kontakte?', $gesehen['url']);
        self::assertStringContainsString('q=Muster', $gesehen['url'], 'Der Suchbegriff geht getrimmt mit.');
        self::assertStringContainsString('limit=25', $gesehen['url']);
        self::assertContains('X-Api-Token: geheim', $gesehen['headers']);
    }

    public function testAnEmptySearchTermIsLeftOut(): void
    {
        $url    = null;
        $client = new MockHttpClient(function (string $method, string $called) use (&$url): MockResponse {
            $url = $called;

            return new MockResponse('{"contacts":[],"count":0}');
        });

        $this->portal($client)->search('   ');

        self::assertIsString($url);
        self::assertStringNotContainsString('q=', $url);
    }

    public function testASingleContactIsRead(): void
    {
        $client = new MockHttpClient(new MockResponse('{"contact":{"id":7,"name":"Max Muster"}}'));

        $contact = $this->portal($client)->find(7);

        self::assertNotNull($contact);
        self::assertSame('Max Muster', $contact->name);
    }

    public function testAContactThePortalDoesNotKnowIsNull(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error":"Kontakt nicht gefunden."}', ['http_code' => 404]));

        self::assertNull($this->portal($client)->find(999));
    }

    public function testARejectedTokenSaysWhereToGetANewOne(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error":"Kein gültiges API-Token."}', ['http_code' => 401]));

        $this->expectException(PortalError::class);
        $this->expectExceptionMessageMatches('/Token abgelehnt/');

        $this->portal($client)->search();
    }

    public function testAServerErrorNamesTheStatus(): void
    {
        $client = new MockHttpClient(new MockResponse('kaputt', ['http_code' => 500]));

        $this->expectException(PortalError::class);
        $this->expectExceptionMessageMatches('/Status 500/');

        $this->portal($client)->search();
    }

    public function testAnUnreachablePortalIsNoErrorPage(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new class('Name oder Dienst nicht bekannt') extends \RuntimeException implements TransportExceptionInterface {};
        });

        $this->expectException(PortalError::class);
        $this->expectExceptionMessageMatches('/nicht erreichbar/');

        $this->portal($client)->search();
    }

    public function testBrokenJsonIsCaught(): void
    {
        $client = new MockHttpClient(new MockResponse('{nicht wirklich json'));

        $this->expectException(PortalError::class);
        $this->expectExceptionMessageMatches('/kein gültiges JSON/');

        $this->portal($client)->search();
    }

    public function testAnAnswerWithoutAListIsCaught(): void
    {
        $client = new MockHttpClient(new MockResponse('{"etwas":"anderes"}'));

        $this->expectException(PortalError::class);
        $this->expectExceptionMessageMatches('/keine Kontaktliste/');

        $this->portal($client)->search();
    }

    public function testWithoutTokenNothingIsRequested(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('Ohne Token darf keine Anfrage hinausgehen.');
        });

        $portal = new PortalContacts($client, 'https://portal.example', '');

        self::assertFalse($portal->isConfigured());

        $this->expectException(PortalError::class);
        $this->expectExceptionMessageMatches('/PORTAL_API_TOKEN/');

        $portal->search();
    }

    public function testWithoutAddressNothingIsRequested(): void
    {
        $portal = new PortalContacts(new MockHttpClient(), '', 'geheim');

        self::assertFalse($portal->isConfigured());

        $this->expectException(PortalError::class);
        $portal->search();
    }

    public function testATrailingSlashInTheAddressDoesNotDoubleUp(): void
    {
        $url    = null;
        $client = new MockHttpClient(function (string $method, string $called) use (&$url): MockResponse {
            $url = $called;

            return new MockResponse('{"contact":{"id":7,"name":"Max Muster"}}');
        });

        (new PortalContacts($client, 'https://portal.example/', 'geheim'))->find(7);

        self::assertSame('https://portal.example/api/kontakte/7', $url);
    }

    public function testRowsThatAreNotContactsAreSkipped(): void
    {
        $client = new MockHttpClient(new MockResponse('{"contacts":[{"id":7,"name":"Max Muster"},"kaputt",null],"count":3}'));

        $contacts = $this->portal($client)->search();

        self::assertCount(1, $contacts);
        self::assertSame('Max Muster', $contacts[0]->name);
    }

    private function portal(MockHttpClient $client): PortalContacts
    {
        return new PortalContacts($client, 'https://portal.example', 'geheim');
    }
}
