<?php

/**
 * Unit tests for PreferencesController.
 *
 * Covers REQ-OBFFUI-005 (retrofit-2026-05-26-frontend-foundation):
 *   - Reject an unsafe key → 400 without touching IConfig.
 *   - Clear a preference → deleteUserValue called, returns {value: null}.
 *   - Unauthenticated request → 401.
 *   - Happy-path get → reads IConfig and returns {value: <stored>}.
 *   - Happy-path set → writes IConfig and returns {value: <stored>}.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\PreferencesController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PreferencesController.
 *
 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-5
 */
class PreferencesControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var PreferencesController
     */
    private PreferencesController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock IConfig.
     *
     * @var IConfig&MockObject
     */
    private IConfig&MockObject $config;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock authenticated user.
     *
     * @var IUser&MockObject
     */
    private IUser&MockObject $user;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request     = $this->createMock(IRequest::class);
        $this->config      = $this->createMock(IConfig::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->user = $this->createMock(IUser::class);
        $this->user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->controller = new PreferencesController(
            request: $this->request,
            config: $this->config,
            userSession: $this->userSession,
        );

    }//end setUp()

    // ------------------------------------------------------------------ //
    // REQ-OBFFUI-005 — unauthenticated access                             //
    // ------------------------------------------------------------------ //

    /**
     * Scenario: unauthenticated getPreference returns 401.
     *
     * @return void
     */
    public function testGetPreferenceReturns401WhenNotLoggedIn(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new PreferencesController(
            request: $this->request,
            config: $this->config,
            userSession: $unauthSession,
        );

        $this->config->expects($this->never())->method('getUserValue');

        $result = $controller->getPreference(key: 'some-key');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testGetPreferenceReturns401WhenNotLoggedIn()

    /**
     * Scenario: unauthenticated setPreference returns 401.
     *
     * @return void
     */
    public function testSetPreferenceReturns401WhenNotLoggedIn(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new PreferencesController(
            request: $this->request,
            config: $this->config,
            userSession: $unauthSession,
        );

        $this->config->expects($this->never())->method('setUserValue');
        $this->config->expects($this->never())->method('deleteUserValue');

        $result = $controller->setPreference(key: 'some-key', value: 'v');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testSetPreferenceReturns401WhenNotLoggedIn()

    // ------------------------------------------------------------------ //
    // REQ-OBFFUI-005 Scenario: Reject an unsafe key                       //
    // ------------------------------------------------------------------ //

    /**
     * A key that sanitises to empty (e.g. all non-alphanum chars) must
     * return 400 without touching IConfig.
     *
     * @return void
     */
    public function testGetPreferenceReturns400OnUnsafeKey(): void
    {
        $this->config->expects($this->never())->method('getUserValue');

        $result = $this->controller->getPreference(key: '!!!');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());

    }//end testGetPreferenceReturns400OnUnsafeKey()

    /**
     * Same 400 contract on setPreference for an empty-sanitising key.
     *
     * @return void
     */
    public function testSetPreferenceReturns400OnUnsafeKey(): void
    {
        $this->config->expects($this->never())->method('setUserValue');
        $this->config->expects($this->never())->method('deleteUserValue');

        $result = $this->controller->setPreference(key: '@#$%', value: 'v');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());

    }//end testSetPreferenceReturns400OnUnsafeKey()

    // ------------------------------------------------------------------ //
    // REQ-OBFFUI-005 Scenario: Clear a preference                         //
    // ------------------------------------------------------------------ //

    /**
     * setPreference with an empty value must call deleteUserValue and
     * return {value: null}.
     *
     * @return void
     */
    public function testSetPreferenceWithEmptyValueClearsAndReturnsNull(): void
    {
        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with(
                userId: 'test-user',
                appName: 'openbuild',
                key: 'pref_mykey'
            );
        $this->config->expects($this->never())->method('setUserValue');

        $result = $this->controller->setPreference(key: 'mykey', value: '');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame(['value' => null], $result->getData());

    }//end testSetPreferenceWithEmptyValueClearsAndReturnsNull()

    // ------------------------------------------------------------------ //
    // REQ-OBFFUI-005 — happy paths                                        //
    // ------------------------------------------------------------------ //

    /**
     * getPreference returns the stored value wrapped in {value: …}.
     *
     * @return void
     */
    public function testGetPreferenceReturnsStoredValue(): void
    {
        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with(
                userId: 'test-user',
                appName: 'openbuild',
                key: 'pref_mykey',
                default: ''
            )
            ->willReturn('stored-value');

        $result = $this->controller->getPreference(key: 'mykey');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame(['value' => 'stored-value'], $result->getData());

    }//end testGetPreferenceReturnsStoredValue()

    /**
     * getPreference returns {value: null} when the key has no stored value.
     *
     * @return void
     */
    public function testGetPreferenceReturnsNullWhenNotSet(): void
    {
        $this->config->method('getUserValue')->willReturn('');

        $result = $this->controller->getPreference(key: 'mykey');

        self::assertSame(['value' => null], $result->getData());

    }//end testGetPreferenceReturnsNullWhenNotSet()

    /**
     * setPreference stores the value under pref_<key> and echoes {value: …}.
     *
     * @return void
     */
    public function testSetPreferenceStoresAndReturnsValue(): void
    {
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with(
                userId: 'test-user',
                appName: 'openbuild',
                key: 'pref_my-flag',
                value: 'true'
            );

        $result = $this->controller->setPreference(key: 'my-flag', value: 'true');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame(['value' => 'true'], $result->getData());

    }//end testSetPreferenceStoresAndReturnsValue()

    /**
     * The sanitizer strips non-[a-z0-9-] chars and truncates to 64 chars.
     * Spaces and punctuation are removed; hyphens and alphanumerics survive.
     *
     * @return void
     */
    public function testSanitisedKeyIsLowercasedAndStripsBadChars(): void
    {
        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with(
                userId: 'test-user',
                appName: 'openbuild',
                key: 'pref_helloworld',
                default: ''
            )
            ->willReturn('');

        // 'Hello World!' → 'helloworld' (uppercase → lower, space stripped,
        // ! stripped; no hyphen in input so none in output).
        $this->controller->getPreference(key: 'Hello World!');

    }//end testSanitisedKeyIsLowercasedAndStripsBadChars()

}//end class
