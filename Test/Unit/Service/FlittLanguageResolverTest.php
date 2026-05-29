<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Service;

use Magento\Framework\Locale\ResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\TbcPayment\Service\FlittLanguageResolver;

/**
 * Batch 3 SIMPLIFY-4 §1: the locale → Flitt-lang mapping (formerly copied in
 * ConfigProvider, Params and Redirect) now lives here once. Flitt supports
 * exactly ka / ru / en, defaulting everything else to en — pinned per branch.
 */
class FlittLanguageResolverTest extends TestCase
{
    private ResolverInterface&MockObject $localeResolver;
    private FlittLanguageResolver $resolver;

    protected function setUp(): void
    {
        $this->localeResolver = $this->createMock(ResolverInterface::class);
        $this->resolver = new FlittLanguageResolver($this->localeResolver);
    }

    /**
     * @dataProvider localeProvider
     */
    public function testResolve(string $locale, string $expected): void
    {
        $this->localeResolver->method('getLocale')->willReturn($locale);

        self::assertSame($expected, $this->resolver->resolve());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function localeProvider(): array
    {
        return [
            'Georgian'              => ['ka_GE', 'ka'],
            'Russian'               => ['ru_RU', 'ru'],
            'English US'            => ['en_US', 'en'],
            'English GB'            => ['en_GB', 'en'],
            'Unsupported -> en'     => ['de_DE', 'en'],
            'Another unsupported'   => ['fr_FR', 'en'],
        ];
    }
}
