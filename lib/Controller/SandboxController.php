<?php

/**
 * Buildiq Sandbox Controller
 *
 * Serves the documents maker code runs in: a code component's own document,
 * and the expression evaluator. Both are loaded into an iframe with
 * `sandbox="allow-scripts"` and no `allow-same-origin`, and both answer with a
 * policy that allows their own inline script and style and nothing else.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-maker-code-runs-only-in-the-sandbox-req-bqcc-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Service\PageCodeGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The sandbox documents for code components and expressions (design D2).
 *
 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-maker-code-runs-only-in-the-sandbox-req-bqcc-003
 */
class SandboxController extends Controller {
	/**
	 * The version path segment that names the production version.
	 *
	 * @var string
	 */
	public const PRODUCTION = 'production';

	/**
	 * The roles that may use an app, and so open its sandbox documents.
	 *
	 * @var array<int, string>
	 */
	private const USE_ROLES = ['owners', 'editors', 'viewers'];

	/**
	 * The evaluator script: runs each expression as a pure function of its
	 * scope and answers with JSON values or an error, never touching the page.
	 *
	 * @var string
	 */
	private const EVALUATOR_SCRIPT = <<<'JS'
'use strict';
window.addEventListener('message', function (event) {
	var message = event.data;
	if (!message || message.type !== 'evaluate' || !Array.isArray(message.items)) {
		return;
	}
	var scope = message.scope || {};
	var results = {};
	message.items.forEach(function (item) {
		try {
			var run = new Function('row', 'values', 'user', 'route', '"use strict"; return (' + String(item.expression) + ');');
			var value = run(scope.row, scope.values, scope.user, scope.route);
			results[item.key] = { value: value === undefined ? null : JSON.parse(JSON.stringify(value)) };
		} catch (error) {
			results[item.key] = { error: String(error && error.message ? error.message : error) };
		}
	});
	event.source.postMessage({ type: 'evaluated', id: message.id, results: results }, '*');
});
JS;

	/**
	 * The evaluator script while the setting is off: every expression answers
	 * that it is switched off, and none runs.
	 *
	 * @var string
	 */
	private const DISABLED_EVALUATOR_SCRIPT = <<<'JS'
'use strict';
window.addEventListener('message', function (event) {
	var message = event.data;
	if (!message || message.type !== 'evaluate' || !Array.isArray(message.items)) {
		return;
	}
	var results = {};
	message.items.forEach(function (item) {
		results[item.key] = { disabled: true };
	});
	event.source.postMessage({ type: 'evaluated', id: message.id, results: results }, '*');
});
JS;

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request             The request.
	 * @param ManifestResolverServiceInterface $manifestResolver    Application and manifest lookup.
	 * @param PermissionResolverInterface      $permissionResolver  Role matching on the app's permissions.
	 * @param IUserSession                     $userSession         The signed-in user.
	 * @param IAppConfig                       $appConfig           The "Allow code on pages" setting.
	 */
	public function __construct(
		IRequest $request,
		private readonly ManifestResolverServiceInterface $manifestResolver,
		private readonly PermissionResolverInterface $permissionResolver,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The document of one code component in an app version.
	 *
	 * Refused for a caller who may not use the app; 404 for an unknown app,
	 * version or component. With "Allow code on pages" off, a placeholder.
	 *
	 * @param string $slug    The app slug.
	 * @param string $version The version slug, or `production`.
	 * @param string $codeId  The id of the page or widget that carries the code.
	 *
	 * @return DataDisplayResponse The document.
	 *
	 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-maker-code-runs-only-in-the-sandbox-req-bqcc-003
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function code(string $slug, string $version, string $codeId): DataDisplayResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->document(body: '', status: Http::STATUS_UNAUTHORIZED, evaluator: false);
		}

		$refusal = $this->refusal(slug: $slug, user: $user);
		if ($refusal !== null) {
			return $this->document(body: '', status: $refusal, evaluator: false);
		}

		$versionSlug = $version;
		if ($version === self::PRODUCTION) {
			$versionSlug = null;
		}

		$code = $this->findCode(
			node: ($this->manifestResolver->resolve(appSlug: $slug, versionSlug: $versionSlug, caller: $user) ?? []),
			codeId: $codeId
		);
		if ($code === null) {
			return $this->document(body: '', status: Http::STATUS_NOT_FOUND, evaluator: false);
		}

		if ($this->allowed() === false) {
			return $this->document(body: '<p>Code on pages is switched off for this Nextcloud.</p>', status: Http::STATUS_OK, evaluator: false);
		}

		return $this->document(body: $this->codeBody(code: $code), status: Http::STATUS_OK, evaluator: false);
	}//end code()

	/**
	 * The expression evaluator document.
	 *
	 * The evaluator holds no app data: expressions and their scope arrive by
	 * message from the page. It still needs a signed-in user, and with "Allow
	 * code on pages" off it answers every expression as switched off.
	 *
	 * @return DataDisplayResponse The document.
	 *
	 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-maker-code-runs-only-in-the-sandbox-req-bqcc-003
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function evaluator(): DataDisplayResponse {
		if ($this->userSession->getUser() === null) {
			return $this->document(body: '', status: Http::STATUS_UNAUTHORIZED, evaluator: true);
		}

		$script = self::DISABLED_EVALUATOR_SCRIPT;
		if ($this->allowed() === true) {
			$script = self::EVALUATOR_SCRIPT;
		}

		return $this->document(body: '<script>' . $script . '</script>', status: Http::STATUS_OK, evaluator: true);
	}//end evaluator()

	/**
	 * The status that refuses this caller the app, or null when they may use it.
	 *
	 * An unknown app and an app the caller has no role on both answer 404,
	 * so the route does not reveal which apps exist.
	 *
	 * @param string $slug The app slug.
	 * @param IUser  $user The caller.
	 *
	 * @return Http::STATUS_NOT_FOUND|null The refusal status, or null.
	 */
	private function refusal(string $slug, IUser $user): ?int {
		$application = $this->manifestResolver->findApplication(appSlug: $slug);
		if ($application === null) {
			return Http::STATUS_NOT_FOUND;
		}

		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			$permissions = [];
		}

		$mayUse = $this->permissionResolver->matchesCaller(
			permissions: $permissions,
			caller: $user,
			userGroups: $this->permissionResolver->resolveUserGroups(user: $user),
			allowAdminBypass: true,
			roles: self::USE_ROLES
		);
		if ($mayUse === true) {
			return null;
		}

		return Http::STATUS_NOT_FOUND;
	}//end refusal()

	/**
	 * Whether "Allow code on pages" is on.
	 *
	 * @return bool True when on.
	 */
	private function allowed(): bool {
		return $this->appConfig->getValueBool(AppIdentity::APP_ID, PageCodeGuard::SETTING, false) === true;
	}//end allowed()

	/**
	 * The `config.code` of the page or widget with this id, anywhere in the manifest.
	 *
	 * @param mixed  $node   A manifest node.
	 * @param string $codeId The page or widget id.
	 *
	 * @return array<string, mixed>|null The code, or null.
	 */
	private function findCode(mixed $node, string $codeId): ?array {
		if (is_array($node) === false) {
			return null;
		}

		$code = ($node['config'][PageCodeGuard::CODE_KEY] ?? null);
		if (($node['id'] ?? null) === $codeId && is_array($code) === true) {
			return $code;
		}

		foreach ($node as $child) {
			$found = $this->findCode(node: $child, codeId: $codeId);
			if ($found !== null) {
				return $found;
			}
		}

		return null;
	}//end findCode()

	/**
	 * The body of a code component's document: its style, markup and script.
	 *
	 * A closing `</style` or `</script` inside the CSS or JavaScript would end
	 * the element early, so it is escaped.
	 *
	 * @param array<string, mixed> $code The `config.code` block.
	 *
	 * @return string The body.
	 */
	private function codeBody(array $code): string {
		$parts = ['html' => '', 'css' => '', 'js' => ''];
		foreach (array_keys($parts) as $key) {
			if (is_string($code[$key] ?? null) === true) {
				$parts[$key] = $code[$key];
			}
		}

		$css = str_ireplace('</style', '<\\/style', $parts['css']);
		$js  = str_ireplace('</script', '<\\/script', $parts['js']);

		return '<style>' . $css . '</style>' . $parts['html'] . '<script>' . $js . '</script>';
	}//end codeBody()

	/**
	 * A sandbox document with its strict policy.
	 *
	 * `EmptyContentSecurityPolicy` is used as is by Nextcloud, not merged with
	 * the default policy: everything is `none` except inline script and style,
	 * `data:` images and framing by buildiq's own pages. The evaluator also
	 * needs `unsafe-eval` to turn an expression into a function. There is no
	 * `connect-src`, so the frame cannot make a request.
	 *
	 * @param string                 $body      The document body.
	 * @param Http::STATUS_*         $status    The HTTP status.
	 * @param bool                   $evaluator Whether this is the evaluator document.
	 *
	 * @return DataDisplayResponse The response.
	 */
	private function document(string $body, int $status, bool $evaluator): DataDisplayResponse {
		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $body . '</body></html>';

		$response = new DataDisplayResponse(data: $html, statusCode: $status, headers: ['Content-Type' => 'text/html; charset=utf-8']);

		$policy = new EmptyContentSecurityPolicy();
		$policy->addAllowedScriptDomain(domain: "'unsafe-inline'");
		if ($evaluator === true) {
			$policy->addAllowedScriptDomain(domain: "'unsafe-eval'");
		}

		$policy->allowInlineStyle(state: true);
		$policy->addAllowedImageDomain(domain: 'data:');
		$policy->addAllowedFrameAncestorDomain(domain: "'self'");
		$policy->addAllowedFormActionDomain(domain: "'none'");
		$response->setContentSecurityPolicy(csp: $policy);

		return $response;
	}//end document()
}//end class
