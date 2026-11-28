<?php

/**
 * ApplicationStories: the user stories an Application keeps.
 *
 * A copilot plan carries `stories[]` whose `servedBy` holds indexes into its
 * own steps. Those indexes mean nothing once the plan has run, so before the
 * `buildiq.createApp` step carries the stories they are turned into names: the
 * schema or page each index pointed at. The creation service then keeps only
 * well-formed stories, within fixed caps, on the Application's `stories`
 * property (ai-copilot-documents-and-code-help D7, REQ-BQCH-007).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Buildiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-created-app-keeps-its-stories-req-bqch-007
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Converts and sanitises Application user stories.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-created-app-keeps-its-stories-req-bqch-007
 */
final class ApplicationStories {

	/**
	 * Most stories an Application keeps.
	 *
	 * @var integer
	 */
	public const MAX_STORIES = 20;

	/**
	 * Most schemas and pages one story names.
	 *
	 * @var integer
	 */
	public const MAX_SERVED_BY = 20;

	/**
	 * Longest kept text per field.
	 *
	 * @var array<string, int>
	 */
	public const MAX_LENGTHS = ['id' => 64, 'role' => 120, 'goal' => 300, 'benefit' => 300, 'title' => 120];

	/**
	 * The step tools a story can be served by, and the kind each stands for.
	 *
	 * @var array<string, string>
	 */
	private const SERVING_TOOLS = ['buildiq.upsertSchema' => 'schema', 'buildiq.upsertPage' => 'page'];

	/**
	 * The plan's stories with each `servedBy` index replaced by the schema or page it points at.
	 *
	 * @param array<int, mixed> $stories The plan's validated stories.
	 * @param array<int, mixed> $steps   The plan's steps, in order.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-created-app-keeps-its-stories-req-bqch-007
	 */
	public static function fromPlan(array $stories, array $steps): array {
		$named = [];
		foreach ($stories as $story) {
			if (is_array($story) === false) {
				continue;
			}

			$servedBy = [];
			foreach ((array)($story['servedBy'] ?? []) as $index) {
				if (is_int($index) === false) {
					continue;
				}

				$reference = self::stepReference(step: ($steps[$index] ?? null));
				if ($reference !== null) {
					$servedBy[] = $reference;
				}
			}

			$story['servedBy'] = $servedBy;
			$named[] = $story;
		}

		return self::sanitise(stories: $named);
	}//end fromPlan()

	/**
	 * Keep only well-formed stories, trimmed and capped.
	 *
	 * A story needs a role and a goal; `id` and `benefit` are optional; each
	 * `servedBy` entry is `{kind: schema|page, id, title}`. Anything else is dropped.
	 *
	 * @param mixed $stories The stories as given.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-created-app-keeps-its-stories-req-bqch-007
	 */
	public static function sanitise(mixed $stories): array {
		if (is_array($stories) === false) {
			return [];
		}

		$kept = [];
		foreach ($stories as $story) {
			$clean = self::sanitiseStory(story: $story);
			if ($clean !== null) {
				$kept[] = $clean;
			}

			if (count($kept) >= self::MAX_STORIES) {
				break;
			}
		}

		return $kept;
	}//end sanitise()

	/**
	 * One story, or null when it has no role or goal.
	 *
	 * @param mixed $story The story as given.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function sanitiseStory(mixed $story): ?array {
		if (is_array($story) === false) {
			return null;
		}

		$role = self::text(value: ($story['role'] ?? null), field: 'role');
		$goal = self::text(value: ($story['goal'] ?? null), field: 'goal');
		if ($role === '' || $goal === '') {
			return null;
		}

		$clean = ['id' => self::text(value: ($story['id'] ?? null), field: 'id'), 'role' => $role, 'goal' => $goal];
		$benefit = self::text(value: ($story['benefit'] ?? null), field: 'benefit');
		if ($benefit !== '') {
			$clean['benefit'] = $benefit;
		}

		$clean['servedBy'] = self::sanitiseServedBy(servedBy: ($story['servedBy'] ?? []));

		return $clean;
	}//end sanitiseStory()

	/**
	 * The well-formed `servedBy` references, capped.
	 *
	 * @param mixed $servedBy The references as given.
	 *
	 * @return array<int, array{kind: string, id: string, title: string}>
	 */
	private static function sanitiseServedBy(mixed $servedBy): array {
		if (is_array($servedBy) === false) {
			return [];
		}

		$kept = [];
		foreach ($servedBy as $reference) {
			if (is_array($reference) === false || in_array(($reference['kind'] ?? null), self::SERVING_TOOLS, true) === false) {
				continue;
			}

			$referenceId = self::text(value: ($reference['id'] ?? null), field: 'id');
			if ($referenceId === '') {
				continue;
			}

			$title = self::text(value: ($reference['title'] ?? null), field: 'title');
			if ($title === '') {
				$title = $referenceId;
			}

			$kept[] = ['kind' => (string)$reference['kind'], 'id' => $referenceId, 'title' => $title];
			if (count($kept) >= self::MAX_SERVED_BY) {
				break;
			}
		}

		return $kept;
	}//end sanitiseServedBy()

	/**
	 * The reference a schema or page step stands for, or null for any other step.
	 *
	 * @param mixed $step A plan step.
	 *
	 * @return array{kind: string, id: string, title: string}|null
	 */
	private static function stepReference(mixed $step): ?array {
		if (is_array($step) === false || isset(self::SERVING_TOOLS[(string)($step['tool'] ?? '')]) === false) {
			return null;
		}

		$kind = self::SERVING_TOOLS[(string)$step['tool']];
		$args = (array)($step['arguments'] ?? []);
		$referenceId = (string)($args['slug'] ?? '');
		if ($kind === 'page') {
			$referenceId = (string)($args['pageId'] ?? '');
		}

		if ($referenceId === '') {
			return null;
		}

		$title = (string)($args['title'] ?? '');
		if ($title === '') {
			$title = $referenceId;
		}

		return ['kind' => $kind, 'id' => $referenceId, 'title' => $title];
	}//end stepReference()

	/**
	 * A trimmed string capped at the field's length, '' for anything that is not a string.
	 *
	 * @param mixed  $value The value.
	 * @param string $field The field whose cap applies.
	 *
	 * @return string
	 */
	private static function text(mixed $value, string $field): string {
		if (is_string($value) === false) {
			return '';
		}

		return mb_substr(trim($value), 0, self::MAX_LENGTHS[$field]);
	}//end text()
}//end class
