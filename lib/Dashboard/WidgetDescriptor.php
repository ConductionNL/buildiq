<?php

/**
 * Buildiq Virtual App Widget Descriptor
 *
 * Everything one promoted widget placement needs, lifted out of a published
 * Application's production manifest and frozen into a value object. One
 * descriptor becomes one Nextcloud Dashboard widget.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Dashboard
 * @package  OCA\Buildiq\Dashboard
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-widget-identity-is-frozen-to-the-application-uuid
 */

declare(strict_types=1);

namespace OCA\Buildiq\Dashboard;

/**
 * One promoted widget placement, ready to register.
 */
final class WidgetDescriptor implements \JsonSerializable {
	/**
	 * Prefix every promoted widget id carries. Frozen: see deriveId().
	 */
	public const ID_PREFIX = 'buildiq-';

	/**
	 * The alphabet Nextcloud's Dashboard manager accepts for a widget id.
	 */
	public const ID_PATTERN = '/^[a-z][a-z0-9\-_]*$/';

	/**
	 * Order used when the ncDashboard object declares none.
	 */
	public const DEFAULT_ORDER = 60;

	/**
	 * The frozen Nextcloud dashboard widget id.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * Immutable Application UUID the id is keyed on.
	 *
	 * @var string
	 */
	public readonly string $applicationUuid;

	/**
	 * Application slug. Links and icons only, never identity.
	 *
	 * @var string
	 */
	public readonly string $applicationSlug;

	/**
	 * Application display name.
	 *
	 * @var string
	 */
	public readonly string $applicationName;

	/**
	 * Every principal that may see this widget: the Application's own
	 * permissions block flattened, plus the placement's `roles`.
	 *
	 * @var array<int,mixed>
	 */
	public readonly array $principals;

	/**
	 * The widget placement's own `id`, as written in the manifest.
	 *
	 * @var string
	 */
	public readonly string $entryId;

	/**
	 * Registry key the browser resolves the widget component by.
	 *
	 * @var string
	 */
	public readonly string $widgetKey;

	/**
	 * Panel title, from ncDashboard.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * Panel icon name, from ncDashboard.
	 *
	 * @var string
	 */
	public readonly string $icon;

	/**
	 * Initial panel order, from ncDashboard. A hint only: once a user
	 * rearranges their dashboard, their arrangement wins.
	 *
	 * @var int
	 */
	public readonly int $order;

	/**
	 * Where the panel title points.
	 *
	 * @var string
	 */
	public readonly string $link;

	/**
	 * Route of the page the placement sits on, inside the virtual app.
	 *
	 * @var string
	 */
	public readonly string $pageRoute;

	/**
	 * The placement's props bag, passed to the browser component.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $props;

	/**
	 * The placement's declarative data source.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $dataSource;

	/**
	 * Constructor.
	 *
	 * @param string $applicationUuid Immutable Application UUID the id is keyed on.
	 * @param string $applicationSlug Application slug, for links and icons only.
	 * @param string $applicationName Application display name.
	 * @param array<int,mixed> $principals Flattened Application permissions plus placement roles.
	 * @param string $entryId The widget placement's own `id`.
	 * @param string $widgetKey The runtime registry key the browser resolves.
	 * @param array{title?:string,icon?:string,order?:int,link?:string} $panel The ncDashboard object.
	 * @param array{pageRoute?:string,props?:array<string,mixed>,dataSource?:array<string,mixed>} $placement Placement details.
	 * @param string|null $id Pre-derived id; omit to derive it here.
	 *
	 * @return void
	 */
	public function __construct(
		string $applicationUuid,
		string $applicationSlug,
		string $applicationName,
		array $principals,
		string $entryId,
		string $widgetKey,
		array $panel,
		array $placement,
		?string $id = null,
	) {
		$this->applicationUuid = $applicationUuid;
		$this->applicationSlug = $applicationSlug;
		$this->applicationName = $applicationName;
		$this->principals = $principals;
		$this->entryId = $entryId;
		$this->widgetKey = $widgetKey;

		$this->title = (string)($panel['title'] ?? $entryId);
		$this->icon = (string)($panel['icon'] ?? '');
		$this->order = (int)($panel['order'] ?? self::DEFAULT_ORDER);
		$this->link = (string)($panel['link'] ?? '');

		$this->pageRoute = (string)($placement['pageRoute'] ?? '/');
		$this->props = (array)($placement['props'] ?? []);
		$this->dataSource = (array)($placement['dataSource'] ?? []);

		$this->id = ($id ?? self::deriveId(applicationUuid: $applicationUuid, entryId: $entryId));
	}//end __construct()

	/**
	 * Derive the frozen widget id from the Application UUID and the entry id.
	 *
	 * 🔴 ONE-WAY DOOR. Nextcloud's Dashboard app stores each user's chosen
	 * widgets by id in its OWN appconfig namespace, which no migration this
	 * app ships can reach. A changed id therefore drops the panel from every
	 * dashboard that had it, with no error, no log line and no 404: the user
	 * sees a dashboard that looks exactly like one where they never added the
	 * widget. Do not key this on the slug or the app name. App slug renames
	 * are a live hazard in this fleet and the UUID is stable across them.
	 *
	 * Normalisation is deterministic and collision-resistant. A segment that
	 * already fits Nextcloud's alphabet passes through untouched; one that
	 * does not is folded into the alphabet AND suffixed with a short digest of
	 * the raw value, so two different raw ids can never fold onto one widget
	 * id. They would otherwise collide inside Manager::registerWidget(), which
	 * throws on a duplicate id, and that throw is caught and logged one frame
	 * up, so the second widget would simply never appear.
	 *
	 * @param string $applicationUuid The Application's immutable UUID.
	 * @param string $entryId The widget placement's own id.
	 *
	 * @return string A widget id matching self::ID_PATTERN.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-widget-identity-is-frozen-to-the-application-uuid
	 */
	public static function deriveId(string $applicationUuid, string $entryId): string {
		return self::ID_PREFIX
			. self::normaliseSegment(raw: $applicationUuid)
			. '-'
			. self::normaliseSegment(raw: $entryId);
	}//end deriveId()

	/**
	 * Fold one id segment into the alphabet Nextcloud accepts.
	 *
	 * @param string $raw The raw segment.
	 *
	 * @return string The normalised segment.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-widget-identity-is-frozen-to-the-application-uuid
	 */
	public static function normaliseSegment(string $raw): string {
		$folded = strtolower($raw);
		$folded = (string)preg_replace('/[^a-z0-9\-_]+/', '-', $folded);
		$folded = trim($folded, '-');

		if ($folded === '') {
			$folded = 'x';
		}

		if ($folded === $raw) {
			return $folded;
		}

		// The raw value did not survive folding unchanged, so the fold is
		// lossy and two raw values could land on one id. Pin the raw value
		// into the result. sha1 of the raw string is deterministic, so
		// re-deriving from the same entry always gives the same id.
		return $folded . '-' . substr(sha1($raw), 0, 8);
	}//end normaliseSegment()

	/**
	 * The descriptor as the browser entry receives it in initial state.
	 *
	 * The `principals` list is deliberately NOT serialised: the browser never
	 * needs it, and shipping an authorization list to the client is how a
	 * hidden surface becomes a readable one.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-the-widget-renders-in-the-nextcloud-panel-chrome
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'applicationSlug' => $this->applicationSlug,
			'applicationName' => $this->applicationName,
			'entryId' => $this->entryId,
			'widgetKey' => $this->widgetKey,
			'pageRoute' => $this->pageRoute,
			'props' => $this->props,
			'dataSource' => $this->dataSource,
			'title' => $this->title,
			'icon' => $this->icon,
			'order' => $this->order,
			'link' => $this->link,
		];
	}//end jsonSerialize()
}//end class
