<?php

/**
 * Unit tests for AppOrganisationMembership.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppOrganisationMembership;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Tests the holder lookup, the membership walk over the parent chain and the multitenancy read, all failing closed.
 *
 * @covers \OCA\Buildiq\Service\AppOrganisationMembership
 */
class AppOrganisationMembershipTest extends TestCase {

	private const HOLDER = 'org-holder';

	/**
	 * A user in the holder organisation is a member; the holder comes from `@self`.
	 *
	 * @return void
	 */
	public function testAUserOfTheHolderIsAMember(): void {
		$membership = $this->membership(memberships: ['alice' => [self::HOLDER]]);

		$this->assertTrue($membership->isMember(['@self' => ['organisation' => self::HOLDER]], 'alice'));
		$this->assertFalse($membership->isMember(['@self' => ['organisation' => self::HOLDER]], 'bob'));
	}//end testAUserOfTheHolderIsAMember()

	/**
	 * A user of a child organisation, whose parent chain holds the holder, is a member.
	 *
	 * @return void
	 */
	public function testAUserOfAChildOrganisationIsAMember(): void {
		$membership = $this->membership(
			memberships: ['carol' => ['org-team', 'org-other']],
			parents: ['org-team' => ['org-mid', self::HOLDER], 'org-other' => ['org-elsewhere']]
		);

		$this->assertTrue($membership->isMember(['@self' => ['organisation' => self::HOLDER]], 'carol'));
		$this->assertFalse($membership->isMember(['@self' => ['organisation' => 'org-mid-unrelated']], 'carol'));
	}//end testAUserOfAChildOrganisationIsAMember()

	/**
	 * Without `@self`, the top-level `organisation` is the holder, trimmed.
	 *
	 * @return void
	 */
	public function testTheTopLevelOrganisationIsTheFallbackHolder(): void {
		$membership = $this->membership(memberships: ['alice' => [self::HOLDER]]);

		$this->assertTrue($membership->isMember(['organisation' => '  '.self::HOLDER.' '], 'alice'));
		$this->assertTrue($membership->isMember(['@self' => 'not-an-array', 'organisation' => self::HOLDER], 'alice'));
	}//end testTheTopLevelOrganisationIsTheFallbackHolder()

	/**
	 * No readable holder, or an empty uid, is never a member, and nothing is read.
	 *
	 * @return void
	 */
	public function testNoHolderOrNoUidIsNeverAMember(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$membership = new AppOrganisationMembership($container, $this->createMock(LoggerInterface::class));

		$this->assertFalse($membership->isMember([], 'alice'));
		$this->assertFalse($membership->isMember(['organisation' => 42], 'alice'));
		$this->assertFalse($membership->isMember(['organisation' => '   '], 'alice'));
		$this->assertFalse($membership->isMember(['organisation' => self::HOLDER], ''));
	}//end testNoHolderOrNoUidIsNeverAMember()

	/**
	 * A mapper that throws makes the user a non-member and logs a warning.
	 *
	 * @return void
	 */
	public function testAnUnreadableMembershipIsNotAMember(): void {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUserId')->willThrowException(new RuntimeException('db down'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(OrganisationMapper::class)->willReturn($mapper);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('organisation membership could not be read'),
			['exception' => 'db down']
		);

		$membership = new AppOrganisationMembership($container, $logger);

		$this->assertFalse($membership->isMember(['organisation' => self::HOLDER], 'alice'));
	}//end testAnUnreadableMembershipIsNotAMember()

	/**
	 * A container that does not hand back a mapper makes the user a non-member.
	 *
	 * @return void
	 */
	public function testAMissingMapperIsNotAMember(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new stdClass());
		$membership = new AppOrganisationMembership($container, $this->createMock(LoggerInterface::class));

		$this->assertFalse($membership->isMember(['organisation' => self::HOLDER], 'alice'));
	}//end testAMissingMapperIsNotAMember()

	/**
	 * Multitenancy is off only when the setting says `enabled: false`.
	 *
	 * @return void
	 */
	public function testMultitenancyIsOffOnlyWhenExplicitlyDisabled(): void {
		$this->assertFalse($this->withSetting('{"enabled":false}')->multitenancyOn());
		$this->assertTrue($this->withSetting('{"enabled":true}')->multitenancyOn());
		$this->assertTrue($this->withSetting('{"enabled":0}')->multitenancyOn(), 'only a literal false turns it off');
		$this->assertTrue($this->withSetting('{}')->multitenancyOn());
		$this->assertTrue($this->withSetting('')->multitenancyOn());
		$this->assertTrue($this->withSetting('not json')->multitenancyOn());
	}//end testMultitenancyIsOffOnlyWhenExplicitlyDisabled()

	/**
	 * The setting is read from OpenRegister's `multitenancy` key.
	 *
	 * @return void
	 */
	public function testMultitenancyReadsOpenRegistersKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('getValueString')
			->with('openregister', 'multitenancy', '')
			->willReturn('{"enabled":false}');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(IAppConfig::class)->willReturn($config);

		$membership = new AppOrganisationMembership($container, $this->createMock(LoggerInterface::class));

		$this->assertFalse($membership->multitenancyOn());
	}//end testMultitenancyReadsOpenRegistersKey()

	/**
	 * An unreadable setting, or no app config at all, counts as on.
	 *
	 * @return void
	 */
	public function testAnUnreadableSettingCountsAsOn(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no config'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('multitenancy setting could not be read'),
			['exception' => 'no config']
		);
		$this->assertTrue((new AppOrganisationMembership($container, $logger))->multitenancyOn());

		$other = $this->createMock(ContainerInterface::class);
		$other->method('get')->willReturn(new stdClass());
		$this->assertTrue((new AppOrganisationMembership($other, $this->createMock(LoggerInterface::class)))->multitenancyOn());
	}//end testAnUnreadableSettingCountsAsOn()

	/**
	 * A membership reader over a mapper with the given memberships and parent chains.
	 *
	 * @param array<string, list<string>> $memberships Organisation uuids per uid.
	 * @param array<string, list<string>> $parents     Parent chain per organisation uuid.
	 *
	 * @return AppOrganisationMembership The reader.
	 */
	private function membership(array $memberships, array $parents = []): AppOrganisationMembership {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUserId')->willReturnCallback(
			static fn (string $uid): array => array_map(
				static fn (string $uuid): Organisation => new Organisation($uuid),
				($memberships[$uid] ?? [])
			)
		);
		$mapper->method('findParentChain')->willReturnCallback(
			static fn (string $uuid): array => ($parents[$uuid] ?? [])
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(OrganisationMapper::class)->willReturn($mapper);

		return new AppOrganisationMembership($container, $this->createMock(LoggerInterface::class));
	}//end membership()

	/**
	 * A membership reader whose app config returns the given multitenancy setting.
	 *
	 * @param string $raw The raw setting.
	 *
	 * @return AppOrganisationMembership The reader.
	 */
	private function withSetting(string $raw): AppOrganisationMembership {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($raw);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($config);

		return new AppOrganisationMembership($container, $this->createMock(LoggerInterface::class));
	}//end withSetting()
}//end class
