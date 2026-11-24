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
 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/specs/page-custom-code/spec.md#requirement-maker-code-runs-only-in-the-sandbox-req-bqcc-003
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
 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/specs/page-custom-code/spec.md#requirement-maker-code-runs-only-in-the-sandbox-req-bqcc-003
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
function isBatch(message) {
	return Boolean(message) && message.type === 'evaluate' && Array.isArray(message.items);
}
function errorText(error) {
	return String(error && error.message ? error.message : error);
}
function evaluateOne(item, scope) {
	try {
		// Dynamic code by design (pages-custom-code D1/D4): this runs only in an iframe
		// sandboxed without same-origin, expressions are admin-gated, and a setting switches it off.
		var run = new Function('row', 'values', 'user', 'route', '"use strict"; return (' + String(item.expression) + ');');
		var value = run(scope.row, scope.values, scope.user, scope.route);
		return { value: value === undefined ? null : JSON.parse(JSON.stringify(value)) };
	} catch (error) {
		return { error: errorText(error) };
	}
}
window.addEventListener('message', function (event) {
	var message = event.data;
	if (!isBatch(message)) {
		return;
	}
	var scope = message.scope || {};
	var results = {};
	message.items.forEach(function (item) {
		results[item.key] = evaluateOne(item, scope);
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
	 * The preview script: the page designer posts the code component being
	 * edited, `{type: "render", code, inputs}`, and the frame replaces its own
	 * document with it. Errors the code throws are posted back as
	 * `{type: "error", message}`, and the inputs are handed in as the page
	 * would, `{type: "inputs", data}`. The document keeps this response's
	 * policy, so the code still has no network and no same origin.
	 *
	 * @var string
	 */
	private const PREVIEW_SCRIPT = <<<'JS'
'use strict';
function isRender(event) {
	var message = event.data;
	if (event.source !== window.parent || !message || message.type !== 'render') {
		return false;
	}
	return Boolean(message.code) && typeof message.code === 'object';
}
window.addEventListener('message', function (event) {
	if (!isRender(event)) {
		return;
	}
	var message = event.data;
	var text = function (value) { return typeof value === 'string' ? value : ''; };
	var css = text(message.code.css).replace(/<\/style/gi, '<\\/style');
	var js = text(message.code.js).replace(/<\/script/gi, '<\\/script');
	var inputs = JSON.stringify(message.inputs || {}).replace(/</g, '\\u003c');
	var report = "window.addEventListener('error', function (e) { parent.postMessage({ type: 'error', message: String(e.message) }, '*'); });";
	var handIn = 'window.postMessage({ type: "inputs", data: ' + inputs + ' }, "*");';
	document.open();
	document.write(
		'<!DOCTYPE html><html><head><meta charset="utf-8">'
		+ '<script>' + report + '<\/script><style>' + css + '</style></head>'
		+ '<body>' + text(message.code.html)
		+ '<script>' + js + '<\/script><script>' + handIn + '<\/script></body></html>'
	);
	document.close();
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
	 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/specs/page-custom-code/spec.md#requirement-maker-code-runs-only-in-the-sandbox-req-bqcc-003
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function code(string $slug, string $version, string $codeId): DataDisplayResponse {
		$user = $this->userSession->getUser();
		$refusal = $this->callerRefusal(slug: $slug, user: $user);
		if ($refusal !== null || $user === null) {
			return $this->document(body: '', status: ($refusal ?? Http::STATUS_UNAUTHORIZED), evaluator: false);
		}

		$code = $this->findCode(
			node: ($this->manifestResolver->resolve(appSlug: $slug, versionSlug: self::versionSlug(version: $version), caller: $user) ?? []),
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
	 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/specs/page-custom-code/spec.md#requirement-maker-code-runs-only-in-the-sandbox-req-bqcc-003
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
	 * The preview document of the page designer.
	 *
	 * It holds no app data and no code of its own: the designer posts the code
	 * component under edit into it (see PREVIEW_SCRIPT), so a maker sees it run,
	 * and its errors, before saving. It needs a signed-in user, and with "Allow
	 * code on pages" off it is a placeholder that runs nothing.
	 *
	 * @return DataDisplayResponse The document.
	 *
	 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/tasks.md#t07
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function preview(): DataDisplayResponse {
		if ($this->userSession->getUser() === null) {
			return $this->document(body: '', status: Http::STATUS_UNAUTHORIZED, evaluator: false);
		}

		if ($this->allowed() === false) {
			return $this->document(body: '<p>Code on pages is switched off for this Nextcloud.</p>', status: Http::STATUS_OK, evaluator: false);
		}

		return $this->document(body: '<script>' . self::PREVIEW_SCRIPT . '</script>', status: Http::STATUS_OK, evaluator: false);
	}//end preview()

	/**
	 * The status that refuses this caller the app: 401 without a signed-in user,
	 * else what refusal() answers; null when they may use it.
	 *
	 * @param string     $slug The app slug.
	 * @param IUser|null $user The caller, or null when nobody is signed in.
	 *
	 * @return Http::STATUS_UNAUTHORIZED|Http::STATUS_NOT_FOUND|null The refusal status, or null.
	 */
	private function callerRefusal(string $slug, IUser|null $user): int|null {
		if ($user === null) {
			return Http::STATUS_UNAUTHORIZED;
		}

		return $this->refusal(slug: $slug, user: $user);
	}//end callerRefusal()

	/**
	 * The version slug the manifest resolver takes: null for `production`.
	 *
	 * @param string $version The version slug from the route.
	 *
	 * @return string|null The version slug, or null for production.
	 */
	private static function versionSlug(string $version): string|null {
		if ($version === self::PRODUCTION) {
			return null;
		}

		return $version;
	}//end versionSlug()

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
	private function refusal(string $slug, IUser $user): int|null {
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
	private function findCode(mixed $node, string $codeId): array|null {
		if (is_array($node) === false) {
			return null;
		}

		$own = self::ownCode(node: $node, codeId: $codeId);
		if ($own !== null) {
			return $own;
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
	 * The `config.code` of this node itself when its id is the one asked for.
	 *
	 * @param array<mixed> $node   A manifest node.
	 * @param string       $codeId The page or widget id.
	 *
	 * @return array<string, mixed>|null The code, or null.
	 */
	private static function ownCode(array $node, string $codeId): array|null {
		if (($node['id'] ?? null) !== $codeId) {
			return null;
		}

		$code = ($node['config'][PageCodeGuard::CODE_KEY] ?? null);
		if (is_array($code) === false) {
			return null;
		}

		return $code;
	}//end ownCode()

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

		$css    = str_ireplace('</style', '<\\/style', $parts['css']);
		$script = str_ireplace('</script', '<\\/script', $parts['js']);

		return '<style>' . $css . '</style>' . $parts['html'] . '<script>' . $script . '</script>';
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

		// The response enforces the sandbox itself, also when the route is
		// opened directly instead of in the embedding iframe: scripts run in an
		// opaque origin, so they reach no cookie, storage or same-origin API
		// (REQ-BQCC-003). An added header wins over the policy object's in
		// Response::getHeaders(), so the directive survives.
		$response->addHeader(name: 'Content-Security-Policy', value: self::sandboxedPolicy(policy: $policy->buildPolicy()));

		return $response;
	}//end document()

	/**
	 * A built policy with the sandbox directive added: scripts only, no same origin.
	 *
	 * @param string $policy The built policy.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/specs/page-custom-code/spec.md#requirement-maker-code-runs-only-in-the-sandbox-req-bqcc-003
	 */
	public static function sandboxedPolicy(string $policy): string {
		$policy = rtrim(trim($policy), ';');
		if ($policy === '') {
			return 'sandbox allow-scripts';
		}

		return $policy . ';sandbox allow-scripts';
	}//end sandboxedPolicy()
}//end class
