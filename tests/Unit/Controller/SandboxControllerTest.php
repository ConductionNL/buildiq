<?php

/**
 * Unit tests for SandboxController.
 *
 * Covers pages-custom-code T03 (REQ-BQCC-003, REQ-BQCC-005): the policy the
 * sandbox documents carry, the refusal for a caller who may not use the app,
 * and the placeholder while "Allow code on pages" is off.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\SandboxController;
use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Service\PageCodeGuard;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see SandboxController}.
 */
#[CoversClass(SandboxController::class)]
#[UsesClass(PageCodeGuard::class)]
final class SandboxControllerTest extends TestCase {

	/**
	 * A manifest with a code page and a code widget on a dashboard.
	 *
	 * @var array<string, mixed>
	 */
	private const MANIFEST = [
		'pages' => [
			[
				'id'     => 'calc',
				'type'   => 'custom',
				'config' => ['code' => ['html' => '<div id="out"></div>', 'css' => 'p{}</style><b>', 'js' => 'x();</script><i>', 'inputs' => ['user']]],
			],
			[
				'id'     => 'home',
				'type'   => 'dashboard',
				'config' => ['widgets' => [['id' => 'tile', 'config' => ['code' => ['html' => '<p>tile</p>']]], ['id' => 'plain', 'config' => []]]],
			],
		],
	];

	/**
	 * @var ManifestResolverServiceInterface&MockObject
	 */
	private ManifestResolverServiceInterface&MockObject $resolver;

	/**
	 * @var PermissionResolverInterface&MockObject
	 */
	private PermissionResolverInterface&MockObject $permissions;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up a signed-in app user, the setting on, and the manifest above.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver    = $this->createMock(ManifestResolverServiceInterface::class);
		$this->permissions = $this->createMock(PermissionResolverInterface::class);
		$this->session     = $this->createMock(IUserSession::class);
		$this->appConfig   = $this->createMock(IAppConfig::class);

		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->resolver->method('findApplication')->willReturnCallback(
			static fn (string $appSlug): ?array => ($appSlug === 'permits') ? ['permissions' => ['viewers' => ['group:staff']]] : null
		);
		$this->resolver->method('resolve')->willReturn(self::MANIFEST);
		$this->permissions->method('resolveUserGroups')->willReturn(['staff']);
		$this->permissions->method('matchesCaller')->willReturn(true);
		$this->appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default=false): bool => ($app === 'buildiq' && $key === PageCodeGuard::SETTING)
		);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return SandboxController
	 */
	private function sut(): SandboxController {
		return new SandboxController(
			request: $this->createMock(IRequest::class),
			manifestResolver: $this->resolver,
			permissionResolver: $this->permissions,
			userSession: $this->session,
			appConfig: $this->appConfig,
		);
	}//end sut()

	/**
	 * The policy a response carries.
	 *
	 * @param DataDisplayResponse $response The response.
	 *
	 * @return string The built policy.
	 */
	private static function policy(DataDisplayResponse $response): string {
		$policy = $response->getContentSecurityPolicy();
		self::assertSame(EmptyContentSecurityPolicy::class, get_class($policy), 'an exact EmptyContentSecurityPolicy is not merged with the default');
		return $policy->buildPolicy();
	}//end policy()

	/**
	 * A code page's document holds its style, markup and script, with the
	 * closing tags inside CSS and JavaScript escaped, under a policy with no
	 * network access.
	 *
	 * @return void
	 */
	public function testACodePageIsServedUnderTheStrictPolicy(): void {
		$this->resolver->expects(self::once())->method('resolve')->with('permits', null, self::isInstanceOf(IUser::class));

		$response = $this->sut()->code(slug: 'permits', version: SandboxController::PRODUCTION, codeId: 'calc');

		self::assertSame(200, $response->getStatus());
		// getHeaders() needs the server container; read the set headers directly.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		self::assertSame('text/html; charset=utf-8', $headers['Content-Type']);
		self::assertStringContainsString('<style>p{}<\/style><b></style><div id="out"></div><script>x();<\/script><i></script>', $response->getData());
		self::assertSame(
			"default-src 'none';base-uri 'none';manifest-src 'self';script-src 'unsafe-inline';style-src  'unsafe-inline';img-src data:;frame-ancestors 'self';form-action 'none'",
			self::policy(response: $response)
		);
	}//end testACodePageIsServedUnderTheStrictPolicy()

	/**
	 * A code widget is found inside a page, and a named version is resolved
	 * by its slug.
	 *
	 * @return void
	 */
	public function testACodeWidgetOfANamedVersionIsFound(): void {
		$this->resolver->expects(self::once())->method('resolve')->with('permits', 'v2', self::anything());

		$response = $this->sut()->code(slug: 'permits', version: 'v2', codeId: 'tile');

		self::assertSame(200, $response->getStatus());
		self::assertStringContainsString('<p>tile</p>', $response->getData());
	}//end testACodeWidgetOfANamedVersionIsFound()

	/**
	 * A page or widget without code, and an unknown id, answer 404.
	 *
	 * @return void
	 */
	public function testAnIdWithoutCodeIsNotFound(): void {
		self::assertSame(404, $this->sut()->code(slug: 'permits', version: 'production', codeId: 'plain')->getStatus());
		self::assertSame(404, $this->sut()->code(slug: 'permits', version: 'production', codeId: 'home')->getStatus());
		self::assertSame(404, $this->sut()->code(slug: 'permits', version: 'production', codeId: 'nope')->getStatus());
	}//end testAnIdWithoutCodeIsNotFound()

	/**
	 * Nobody signed in answers 401; a caller with no role on the app, and an
	 * unknown app, answer the same 404 and never read the manifest.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotUseTheAppIsRefused(): void {
		$this->resolver->expects(self::never())->method('resolve');
		$this->permissions = $this->createMock(PermissionResolverInterface::class);
		$this->permissions->expects(self::once())->method('matchesCaller')
			->with(['viewers' => ['group:staff']], self::isInstanceOf(IUser::class), [], true, ['owners', 'editors', 'viewers'])
			->willReturn(false);

		$noRole  = $this->sut()->code(slug: 'permits', version: 'production', codeId: 'calc');
		$unknown = $this->sut()->code(slug: 'other', version: 'production', codeId: 'calc');

		self::assertSame([404, ''], [$noRole->getStatus(), strip_tags($noRole->getData())]);
		self::assertSame(404, $unknown->getStatus());

		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn(null);
		self::assertSame(401, $this->sut()->code(slug: 'permits', version: 'production', codeId: 'calc')->getStatus());
		self::assertSame(401, $this->sut()->evaluator()->getStatus());
	}//end testACallerWhoMayNotUseTheAppIsRefused()

	/**
	 * With the setting off a code component is a placeholder and none of its
	 * code is served, and the evaluator runs nothing.
	 *
	 * @return void
	 */
	public function testWithTheSettingOffTheDocumentsArePlaceholders(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueBool')->willReturn(false);

		$code      = $this->sut()->code(slug: 'permits', version: 'production', codeId: 'calc');
		$evaluator = $this->sut()->evaluator();

		self::assertSame(200, $code->getStatus());
		self::assertStringContainsString('Code on pages is switched off', $code->getData());
		self::assertStringNotContainsString('x();', $code->getData());
		self::assertStringContainsString('disabled: true', $evaluator->getData());
		self::assertStringNotContainsString('new Function', $evaluator->getData());
	}//end testWithTheSettingOffTheDocumentsArePlaceholders()

	/**
	 * The evaluator turns expressions into functions, so it alone may eval;
	 * it still makes no requests.
	 *
	 * @return void
	 */
	public function testTheEvaluatorMayEvalButNotConnect(): void {
		$response = $this->sut()->evaluator();
		$policy   = self::policy(response: $response);

		self::assertSame(200, $response->getStatus());
		self::assertStringContainsString('new Function', $response->getData());
		self::assertStringContainsString("script-src 'unsafe-inline' 'unsafe-eval';", $policy);
		self::assertStringNotContainsString('connect-src', $policy);
	}//end testTheEvaluatorMayEvalButNotConnect()
}//end class
