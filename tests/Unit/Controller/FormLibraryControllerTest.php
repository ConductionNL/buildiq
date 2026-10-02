<?php

/**
 * Unit tests for FormLibraryController.
 *
 * @category Tests
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
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\FormLibraryController;
use OCA\Buildiq\Service\GitHubFormCatalogService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The form library's GitHub search endpoint.
 */
final class FormLibraryControllerTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * @var GitHubFormCatalogService&MockObject
	 */
	private $formCatalog;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->formCatalog = $this->createMock(GitHubFormCatalogService::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return FormLibraryController
	 */
	private function controller(): FormLibraryController {
		return new FormLibraryController(
			request: $this->request,
			logger: $this->createMock(LoggerInterface::class),
			userSession: $this->userSession,
			formCatalog: $this->formCatalog
		);
	}//end controller()

	/**
	 * Sign in a user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function authenticate(string $uid = 'bob'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()


	/**
	 * githubSearch (REQ-BQGL-005): anonymous callers get 401 and GitHub is not asked.
	 *
	 * @return void
	 */
	public function testGithubFormSearchRejectsAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->formCatalog->expects(self::never())->method('searchForms');

		$response = $this->controller()->githubSearch();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testGithubFormSearchRejectsAnonymous()

	/**
	 * githubSearch: a signed-in caller gets the form cards for their query.
	 *
	 * @return void
	 */
	public function testGithubFormSearchReturnsFormCards(): void {
		$this->authenticate();
		$this->request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'q' ? 'subsidie' : null);
		$this->formCatalog->expects(self::once())
			->method('searchForms')
			->with('subsidie', 'bob', null)
			->willReturn(
				[
					'outcome' => 'ok',
					'cards' => [['repo' => 'subsidie-formulier', 'installable' => true]],
					'brokerUsed' => false,
					'rateLimited' => false,
				]
			);

		$response = $this->controller()->githubSearch();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('subsidie-formulier', $response->getData()['cards'][0]['repo']);
		self::assertSame('ok', $response->getData()['outcome']);
	}//end testGithubFormSearchReturnsFormCards()

	/**
	 * githubSearch: a failing lookup reads as unreachable, not as no forms.
	 *
	 * @return void
	 */
	public function testGithubFormSearchFailureIsUnreachable(): void {
		$this->authenticate();
		$this->request->method('getParam')->willReturn(null);
		$this->formCatalog->method('searchForms')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller()->githubSearch();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('github_unreachable', $response->getData()['outcome']);
		self::assertSame([], $response->getData()['cards']);
	}//end testGithubFormSearchFailureIsUnreachable()
}//end class
