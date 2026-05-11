<?php

declare(strict_types=1);

namespace OCA\OpenBuilt\Tests\Unit\Service;

use OCA\OpenBuilt\Service\PlaceholderResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PlaceholderResolver}.
 */
final class PlaceholderResolverTest extends TestCase
{
    /**
     * Token replacement substitutes every placeholder.
     */
    public function testResolveReplacesAllPlaceholders(): void
    {
        $resolver = new PlaceholderResolver();
        $map = $resolver->buildMap([
            'appId' => 'my-cool-app',
            'appNamespace' => 'MyCoolApp',
            'appName' => 'My Cool App',
        ]);
        $resolved = $resolver->resolve('id: {{appId}}, ns: {{appNamespace}}', $map);
        self::assertSame('id: my-cool-app, ns: MyCoolApp', $resolved);
    }//end testResolveReplacesAllPlaceholders()

    /**
     * Idempotency: re-resolving a resolved string is a no-op.
     */
    public function testResolveIsIdempotent(): void
    {
        $resolver = new PlaceholderResolver();
        $map = $resolver->buildMap(['appId' => 'demo']);
        $first  = $resolver->resolve('namespace: {{appId}}', $map);
        $second = $resolver->resolve($first, $map);
        self::assertSame($first, $second);
    }//end testResolveIsIdempotent()

    /**
     * PascalCase'd output normalises hyphens / spaces.
     */
    public function testPascalCase(): void
    {
        $resolver = new PlaceholderResolver();
        self::assertSame('MyCoolApp', $resolver->pascalCase('my-cool-app'));
        self::assertSame('FooBarBaz', $resolver->pascalCase('foo bar baz'));
    }//end testPascalCase()

    /**
     * Slugger lowercases + hyphenates.
     */
    public function testSlug(): void
    {
        $resolver = new PlaceholderResolver();
        self::assertSame('my-app', $resolver->slug('My App'));
        self::assertSame('foo-bar', $resolver->slug('Foo_Bar!!'));
    }//end testSlug()
}//end class
