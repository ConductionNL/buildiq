<?php

/**
 * Unit tests for RenameDutchRuleColumns.
 *
 * Covers the decisions that determine what the migration touches — which shard
 * tables are in scope — plus two invariants the step relies on that were
 * previously asserted only in prose.
 *
 * The DDL/DML paths are deliberately not unit-tested: they need a live
 * database and are verified by running the repair step, not here. What IS
 * testable in isolation is the logic deciding which tables and columns are in
 * scope, and that is what these tests pin.
 *
 * @category Tests
 * @package  OCA\OpenBuild\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Repair;

use OCA\OpenBuild\Repair\RenameDutchRuleColumns;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenBuild\Repair\RenameDutchRuleColumns
 */
class RenameDutchRuleColumnsTest extends TestCase
{
    /**
     * The step under test, wired with doubles.
     *
     * @var RenameDutchRuleColumns
     */
    private RenameDutchRuleColumns $step;

    /**
     * Build the step WITHOUT running its constructor.
     *
     * The methods under test are pure — they read neither $db nor $logger — so
     * no collaborators are needed. Mocking IDBConnection would drag in
     * Doctrine\DBAL\ParameterType, which this app's test environment does not
     * install; the mock fails to build and every test errors before it runs.
     * Skipping the constructor keeps the test honest about what it exercises.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->step = (new ReflectionClass(RenameDutchRuleColumns::class))->newInstanceWithoutConstructor();

    }//end setUp()

    /**
     * Invoke a private method on the step.
     *
     * @param string       $name Method name.
     * @param array<mixed> $args Positional arguments.
     *
     * @return mixed
     */
    private function call(string $name, array $args)
    {
        $m = new ReflectionMethod(RenameDutchRuleColumns::class, $name);
        $m->setAccessible(true);
        return $m->invokeArgs($this->step, $args);

    }//end call()

    /**
     * Read a private constant off the step.
     *
     * @param string $name Constant name.
     *
     * @return mixed
     */
    private function constant(string $name)
    {
        return (new ReflectionClass(RenameDutchRuleColumns::class))->getConstant($name);

    }//end constant()

    /**
     * A shard table whose schema id matches is selected.
     *
     * @return void
     */
    public function testMatchesShardOfTheSchema(): void
    {
        self::assertTrue($this->call('isShardOfSchema', ['oc_openregister_table_206_88', ['_88']]));

    }//end testMatchesShardOfTheSchema()

    /**
     * The SAME schema in a DIFFERENT register is also selected.
     *
     * openbuild's schemas live in two registers (206 and 2421 were observed),
     * so matching only the first migrates half the data. The suffix match is
     * what makes both registers' shards eligible.
     *
     * @return void
     */
    public function testMatchesTheSameSchemaInEveryRegister(): void
    {
        self::assertTrue($this->call('isShardOfSchema', ['oc_openregister_table_206_88', ['_88']]));
        self::assertTrue($this->call('isShardOfSchema', ['oc_openregister_table_2421_88', ['_88']]));

    }//end testMatchesTheSameSchemaInEveryRegister()

    /**
     * Schema 88 must not match schema 188's shard.
     *
     * The suffix carries a leading underscore precisely so that `_88` cannot
     * match `..._188`. Without it the step migrates an unrelated schema, and
     * this test goes red if the underscore is dropped.
     *
     * @return void
     */
    public function testDoesNotMatchALongerSchemaId(): void
    {
        self::assertFalse($this->call('isShardOfSchema', ['oc_openregister_table_206_188', ['_88']]));

    }//end testDoesNotMatchALongerSchemaId()

    /**
     * A table without the openregister marker is never selected.
     *
     * The suffix alone is not sufficient — `oc_some_other_88` ends in `_88`.
     * The marker check is what keeps the step off tables it does not own.
     *
     * @return void
     */
    public function testRequiresTheOpenregisterMarker(): void
    {
        self::assertFalse($this->call('isShardOfSchema', ['oc_some_other_88', ['_88']]));
        self::assertFalse($this->call('isShardOfSchema', ['', ['_88']]));

    }//end testRequiresTheOpenregisterMarker()

    /**
     * Every destination is snake_case, never camelCase.
     *
     * MagicMapper stores `ownerApp` as `owner_app`, and its de-duplication path
     * DROPS a camelCase column whose snake_case twin exists — so a camelCase
     * destination here would be deleted by the mapper on the next sync.
     *
     * @return void
     */
    public function testEveryDestinationIsSnakeCase(): void
    {
        $map = $this->constant('COLUMN_MAP');
        self::assertIsArray($map);
        foreach ($map as $old => $new) {
            self::assertSame(
                strtolower($new),
                $new,
                "Destination '$new' (from '$old') must be snake_case, not camelCase"
            );
        }

    }//end testEveryDestinationIsSnakeCase()

    /**
     * No two Dutch columns map to the same English name.
     *
     * This step has no collision guard, unlike its siblings in procest and
     * softwarecatalog — it does not need one only for as long as the map stays
     * injective. If a later edit introduces a duplicate destination, the step
     * would silently overwrite one value with another, and this test is what
     * catches that at review time rather than in production.
     *
     * @return void
     */
    public function testColumnMapIsInjective(): void
    {
        $map = $this->constant('COLUMN_MAP');
        self::assertSame(
            count($map),
            count(array_unique(array_values($map))),
            'Two Dutch columns map to the same English name; add a collision guard first'
        );

    }//end testColumnMapIsInjective()

    /**
     * The step reports a human-readable name.
     *
     * @return void
     */
    public function testGetName(): void
    {
        self::assertNotSame('', $this->step->getName());

    }//end testGetName()
}//end class
