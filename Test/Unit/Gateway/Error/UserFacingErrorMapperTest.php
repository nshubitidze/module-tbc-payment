<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Gateway\Error;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\TbcPayment\Gateway\Error\UserFacingErrorMapper;

/**
 * Regression coverage for the Flitt error code -> user copy map documented
 * in `docs/error-code-map.md` §2 + §5.
 *
 * The mapper is a pure function: same (code, locale) always yields the same
 * copy. Tests therefore only vary the inputs and assert the returned
 * LocalizedException's message.
 */
class UserFacingErrorMapperTest extends TestCase
{
    private ResolverInterface&MockObject $localeResolver;

    protected function setUp(): void
    {
        $this->localeResolver = $this->createMock(ResolverInterface::class);
    }

    public function testRow1Code1002ApplicationErrorEn(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(1002, 'Application error');

        self::assertInstanceOf(LocalizedException::class, $exception);
        self::assertSame(
            'System error. Please try again in a moment.',
            $exception->getMessage(),
        );
    }

    public function testRow1Code1002ApplicationErrorKa(): void
    {
        $mapper = $this->makeMapper('ka_GE');

        $exception = $mapper->toLocalizedException(1002, 'Application error');

        self::assertSame(
            'სისტემური შეცდომა. გთხოვთ, სცადოთ ცოტა ხანში ხელახლა.',
            $exception->getMessage(),
        );
    }

    public function testRow2Code1006MerchantMisconfigured(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(1006, 'Bad merchant config');

        self::assertSame(
            'Payment system configuration error. Please contact support.',
            $exception->getMessage(),
        );
    }

    public function testRow2Code2003MerchantMisconfigured(): void
    {
        $mapper = $this->makeMapper('ka_GE');

        $exception = $mapper->toLocalizedException(2003, 'Bad merchant config');

        self::assertSame(
            'გადახდის სისტემის კონფიგურაციის შეცდომა. დაუკავშირდით მხარდაჭერას.',
            $exception->getMessage(),
        );
    }

    public function testRow3Code1011ParameterMissing(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(1011, 'Parameter missing');

        self::assertSame(
            'Payment information not found. Please contact support.',
            $exception->getMessage(),
        );
    }

    public function testRow4Code1013DuplicateOrderId(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(1013, 'Duplicate order_id');

        self::assertSame(
            'This payment has already been processed. Please check your orders.',
            $exception->getMessage(),
        );
    }

    public function testRow4Code2004DuplicateOrderId(): void
    {
        $mapper = $this->makeMapper('ka_GE');

        $exception = $mapper->toLocalizedException(2004, 'Duplicate order_id');

        self::assertSame(
            'გადახდა უკვე დამუშავებულია. შეამოწმეთ თქვენი შეკვეთები.',
            $exception->getMessage(),
        );
    }

    public function testRow5Code1014InvalidSignature(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(1014, 'Invalid signature');

        self::assertSame(
            'Payment system configuration error. Please contact support.',
            $exception->getMessage(),
        );
    }

    public function testRow6Code1016MerchantNotFound(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(1016, 'Merchant not found');

        self::assertSame(
            'Payment system temporarily unavailable. Please try later.',
            $exception->getMessage(),
        );
    }

    public function testRow7Code1027PreauthNotAllowed(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(1027, 'Preauth not allowed');

        self::assertSame(
            'This payment option is not available for this order.',
            $exception->getMessage(),
        );
    }

    public function testRow8Code2050CardDeclineRangeEn(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(2050, 'Card declined');

        self::assertSame(
            'Your bank declined the payment. Please try another card or contact your bank.',
            $exception->getMessage(),
        );
    }

    public function testRow8Code2099CardDeclineRangeKa(): void
    {
        $mapper = $this->makeMapper('ka_GE');

        $exception = $mapper->toLocalizedException(2099, 'Card declined');

        self::assertSame(
            'ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით ან დაუკავშირდით თქვენს ბანკს.',
            $exception->getMessage(),
        );
    }

    public function testRow9Code1050GenericAuthBucket(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(1050, 'Something in 1000 range');

        self::assertSame(
            'Payment couldn\'t be completed. Please try again.',
            $exception->getMessage(),
        );
    }

    public function testRow10Code2500GenericCardBucket(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(2500, 'Issuer decline');

        self::assertSame(
            'Bank declined the payment. Try another card.',
            $exception->getMessage(),
        );
    }

    public function testRow11Code3100ThreeDSBucket(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(3100, '3DS failed');

        self::assertSame(
            'Bank verification failed. Please try again.',
            $exception->getMessage(),
        );
    }

    public function testRow12Code5000SystemInfrastructureBucket(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(5000, 'System error');

        self::assertSame(
            'System error. Please try again in a moment.',
            $exception->getMessage(),
        );
    }

    public function testRow13DefaultFallbackForUnmappedCode(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(9999, 'Completely unknown code');

        self::assertSame(
            'Payment couldn\'t be completed. Please try again or contact support.',
            $exception->getMessage(),
        );
    }

    public function testCastsStringErrorCodeToInt(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException('1002', 'Application error');

        self::assertSame(
            'System error. Please try again in a moment.',
            $exception->getMessage(),
        );
    }

    public function testZeroCodeFallsThroughToDefault(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(0, '');

        self::assertSame(
            'Payment couldn\'t be completed. Please try again or contact support.',
            $exception->getMessage(),
        );
    }

    public function testNonNumericStringCodeFallsThroughToDefault(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException('NOT_A_CODE', 'weird');

        self::assertSame(
            'Payment couldn\'t be completed. Please try again or contact support.',
            $exception->getMessage(),
        );
    }

    public function testRussianLocaleFallsThroughToEnglishPerArchitectDecision(): void
    {
        // architect-scope.md §2.2.2 explicitly defers Russian copy — `ru` must resolve to English.
        $mapper = $this->makeMapper('ru_RU');

        $exception = $mapper->toLocalizedException(1002, 'Application error');

        self::assertSame(
            'System error. Please try again in a moment.',
            $exception->getMessage(),
        );
    }

    public function testRequestIdDoesNotLeakIntoUserMessage(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(
            2050,
            'Card declined',
            'abc123-leak-me-please',
        );

        self::assertStringNotContainsString('abc123', $exception->getMessage());
        self::assertStringNotContainsString('leak', $exception->getMessage());
    }

    public function testRawErrorMessageDoesNotLeakIntoUserMessage(): void
    {
        $mapper = $this->makeMapper('en_US');

        $exception = $mapper->toLocalizedException(1002, 'Application error');

        // The raw Flitt string must NEVER appear in the mapped user copy —
        // if it did, we'd have defeated the whole translation layer.
        self::assertStringNotContainsString('Application error', $exception->getMessage());
    }

    private function makeMapper(string $locale): UserFacingErrorMapper
    {
        $this->localeResolver->method('getLocale')->willReturn($locale);

        return new UserFacingErrorMapper(
            localeResolver: $this->localeResolver,
        );
    }
}
