<?php

/**
 * Unit tests for RemoteTemplateStoreService (remote template "store").
 *
 * Covers openbuild-remote-template-store: searchTemplates happy path with
 * manifest/companionSchemas stripped from cards, the `_search` query forward,
 * the unreachable / invalid-body / not-configured outcomes, and the
 * fail-closed SSRF scheme guard (non-http schemes make no network call).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\RemoteTemplateStoreService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for RemoteTemplateStoreService.
 */
class RemoteTemplateStoreServiceTest extends TestCase
{
    /**
     * Mock HTTP client factory.
     *
     * @var IClientService&MockObject
     */
    private IClientService&MockObject $clientService;

    /**
     * Mock app config.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clientService = $this->createMock(IClientService::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

    }//end setUp()

    /**
     * Build the service under test.
     *
     * @return RemoteTemplateStoreService
     */
    private function service(): RemoteTemplateStoreService
    {
        return new RemoteTemplateStoreService(
            clientService: $this->clientService,
            appConfig: $this->appConfig,
            logger: $this->logger
        );

    }//end service()

    /**
     * Wire IAppConfig::getValueString to return the given registry config.
     *
     * @param string $url      The registry base URL.
     * @param string $register The register segment.
     * @param string $token    The optional read token.
     *
     * @return void
     */
    private function configure(string $url, string $register='openbuild', string $token=''): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key) use ($url, $register, $token): string {
                return match ($key) {
                    'registry_url'      => $url,
                    'registry_register' => $register,
                    'registry_token'    => $token,
                    default             => '',
                };
            });

    }//end configure()

    /**
     * Build a mock IResponse with the given status + JSON-encodable body.
     *
     * @param int   $status The HTTP status code.
     * @param mixed $body   The body (string passed through, anything else JSON-encoded).
     *
     * @return IResponse&MockObject
     */
    private function mockResponse(int $status, mixed $body): IResponse&MockObject
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(is_string($body) === true ? $body : json_encode($body));

        return $response;

    }//end mockResponse()

    /**
     * Happy path: a configured registry returns templates → outcome `ok` and
     * normalised cards with the heavy manifest / companionSchemas STRIPPED.
     *
     * @return void
     */
    public function testSearchTemplatesHappyPathStripsManifest(): void
    {
        $this->configure(url: 'https://store.example.test');

        $body = [
            'results' => [
                [
                    'slug'             => 'permit-tracker',
                    'title'            => 'Permit Tracker',
                    'description'      => 'Index + form + kanban.',
                    'useCase'          => 'Municipal permits',
                    'category'         => 'government-services',
                    'version'          => '1.2.0',
                    'manifest'         => ['pages' => [['id' => 'home']]],
                    'companionSchemas' => [['slug' => 'permit']],
                ],
            ],
        ];

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($this->mockResponse(status: 200, body: $body));
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->service()->searchTemplates(query: null);

        self::assertSame(RemoteTemplateStoreService::OUTCOME_OK, $result['outcome']);
        self::assertCount(1, $result['cards']);

        $card = $result['cards'][0];
        self::assertSame('permit-tracker', $card['slug']);
        self::assertSame('Permit Tracker', $card['title']);
        self::assertSame('1.2.0', $card['version']);
        self::assertArrayNotHasKey('manifest', $card);
        self::assertArrayNotHasKey('companionSchemas', $card);

    }//end testSearchTemplatesHappyPathStripsManifest()

    /**
     * The search term is forwarded to the remote request as query `_search`.
     *
     * @return void
     */
    public function testSearchTermIsForwardedAsSearchQuery(): void
    {
        $this->configure(url: 'https://store.example.test');

        $captured = null;
        $client   = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(
            function (string $url, array $options) use (&$captured): IResponse {
                $captured = $options;
                return $this->mockResponse(status: 200, body: ['results' => []]);
            }
        );
        $this->clientService->method('newClient')->willReturn($client);

        $this->service()->searchTemplates(query: 'permits');

        self::assertIsArray($captured);
        self::assertArrayHasKey('query', $captured);
        self::assertSame('permits', $captured['query']['_search'] ?? null);

    }//end testSearchTermIsForwardedAsSearchQuery()

    /**
     * SSRF hardening (H2): the registry fetch MUST NOT follow redirects, so a
     * public host cannot 302-redirect to a private/link-local/metadata address
     * (or exploit DNS rebinding) with the Bearer token attached.
     *
     * @return void
     */
    public function testFetchDisablesRedirectFollowing(): void
    {
        $this->configure(url: 'https://store.example.test');

        $captured = null;
        $client   = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(
            function (string $url, array $options) use (&$captured): IResponse {
                $captured = $options;
                return $this->mockResponse(status: 200, body: ['results' => []]);
            }
        );
        $this->clientService->method('newClient')->willReturn($client);

        $this->service()->searchTemplates(query: 'anything');

        self::assertIsArray($captured);
        self::assertArrayHasKey('allow_redirects', $captured);
        self::assertFalse($captured['allow_redirects'], 'registry fetch must not follow redirects');

    }//end testFetchDisablesRedirectFollowing()

    /**
     * A thrown client error (registry unreachable / timeout) → outcome
     * `store_unreachable` and empty cards.
     *
     * @return void
     */
    public function testSearchTemplatesUnreachableWhenClientThrows(): void
    {
        $this->configure(url: 'https://store.example.test');

        $client = $this->createMock(IClient::class);
        $client->method('get')->willThrowException(new RuntimeException('connection refused'));
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->service()->searchTemplates(query: null);

        self::assertSame(RemoteTemplateStoreService::OUTCOME_UNREACHABLE, $result['outcome']);
        self::assertSame([], $result['cards']);

    }//end testSearchTemplatesUnreachableWhenClientThrows()

    /**
     * A non-2xx response from the registry → outcome `store_unreachable`.
     *
     * @return void
     */
    public function testSearchTemplatesUnreachableOnNon2xxStatus(): void
    {
        $this->configure(url: 'https://store.example.test');

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($this->mockResponse(status: 503, body: ['results' => []]));
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->service()->searchTemplates(query: null);

        self::assertSame(RemoteTemplateStoreService::OUTCOME_UNREACHABLE, $result['outcome']);
        self::assertSame([], $result['cards']);

    }//end testSearchTemplatesUnreachableOnNon2xxStatus()

    /**
     * An unparseable / non-JSON body → outcome `store_invalid_response`.
     *
     * @return void
     */
    public function testSearchTemplatesInvalidResponseOnUnparseableBody(): void
    {
        $this->configure(url: 'https://store.example.test');

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($this->mockResponse(status: 200, body: 'not-json-at-all<<'));
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->service()->searchTemplates(query: null);

        self::assertSame(RemoteTemplateStoreService::OUTCOME_INVALID, $result['outcome']);
        self::assertSame([], $result['cards']);

    }//end testSearchTemplatesInvalidResponseOnUnparseableBody()

    /**
     * No registry configured (empty base URL) → outcome `not_configured` and
     * the HTTP client factory is NEVER invoked.
     *
     * @return void
     */
    public function testSearchTemplatesNotConfiguredMakesNoCall(): void
    {
        $this->configure(url: '');

        $this->clientService->expects(self::never())->method('newClient');

        $result = $this->service()->searchTemplates(query: 'permits');

        self::assertSame(RemoteTemplateStoreService::OUTCOME_NOT_CONFIGURED, $result['outcome']);
        self::assertSame([], $result['cards']);

    }//end testSearchTemplatesNotConfiguredMakesNoCall()

    /**
     * SSRF / scheme guard: a non-http(s) scheme is rejected fail-closed by the
     * local fallback guard — no network call is made and the outcome is
     * `store_unreachable`. (`isConfigured` is true because the base URL is
     * non-empty, so the request proceeds to the guard.)
     *
     * @return void
     */
    public function testNonHttpSchemeIsRejectedWithoutFetch(): void
    {
        $this->configure(url: 'ftp://store.example.test');

        // The guard runs before any client call; the factory must never fire.
        $this->clientService->expects(self::never())->method('newClient');

        $result = $this->service()->searchTemplates(query: null);

        self::assertSame(RemoteTemplateStoreService::OUTCOME_UNREACHABLE, $result['outcome']);
        self::assertSame([], $result['cards']);

    }//end testNonHttpSchemeIsRejectedWithoutFetch()

    /**
     * resolveTemplate returns the FULL payload (manifest + companionSchemas
     * intact) for a matching slug.
     *
     * @return void
     */
    public function testResolveTemplateReturnsFullPayloadForMatchingSlug(): void
    {
        $this->configure(url: 'https://store.example.test');

        $object = [
            'slug'             => 'permit-tracker',
            'title'            => 'Permit Tracker',
            'manifest'         => ['pages' => [['id' => 'home']]],
            'companionSchemas' => [['slug' => 'permit']],
        ];

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($this->mockResponse(status: 200, body: ['results' => [$object]]));
        $this->clientService->method('newClient')->willReturn($client);

        $resolved = $this->service()->resolveTemplate(slug: 'permit-tracker');

        self::assertNotNull($resolved);
        self::assertSame('permit-tracker', $resolved['slug']);
        self::assertArrayHasKey('manifest', $resolved);
        self::assertArrayHasKey('companionSchemas', $resolved);

    }//end testResolveTemplateReturnsFullPayloadForMatchingSlug()

    /**
     * resolveTemplate returns null when no configured registry exists (no call).
     *
     * @return void
     */
    public function testResolveTemplateReturnsNullWhenNotConfigured(): void
    {
        $this->configure(url: '');

        $this->clientService->expects(self::never())->method('newClient');

        self::assertNull($this->service()->resolveTemplate(slug: 'permit-tracker'));

    }//end testResolveTemplateReturnsNullWhenNotConfigured()

    /**
     * isConfigured reflects a non-empty trimmed base URL.
     *
     * @return void
     */
    public function testIsConfiguredReflectsBaseUrl(): void
    {
        $this->configure(url: '   ');
        self::assertFalse($this->service()->isConfigured());

    }//end testIsConfiguredReflectsBaseUrl()
}//end class
