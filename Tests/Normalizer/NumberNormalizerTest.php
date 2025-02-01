<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Serializer\Tests\Normalizer;

use BcMath\Number;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\NumberNormalizer;

/**
 * @requires PHP 8.4
 * @requires extension bcmath
 * @requires extension gmp
 */
class NumberNormalizerTest extends TestCase
{
    private NumberNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new NumberNormalizer();
    }

    /**
     * @dataProvider supportsNormalizationProvider
     */
    public function testSupportsNormalization(mixed $data, bool $expected)
    {
        $this->assertSame($expected, $this->normalizer->supportsNormalization($data));
    }

    public static function supportsNormalizationProvider(): iterable
    {
        yield 'GMP object' => [new \GMP('0b111'), true];
        yield 'Number object' => [new Number('1.23'), true];
        yield 'object with similar properties as Number' => [(object) ['value' => '1.23', 'scale' => 2], false];
        yield 'stdClass' => [new \stdClass(), false];
        yield 'string' => ['1.23', false];
        yield 'float' => [1.23, false];
        yield 'null' => [null, false];
    }

    /**
     * @dataProvider normalizeGoodValueProvider
     */
    public function testNormalize(mixed $data, mixed $expected)
    {
        $this->assertSame($expected, $this->normalizer->normalize($data));
    }

    public static function normalizeGoodValueProvider(): iterable
    {
        yield 'Number with scale=2' => [new Number('1.23'), '1.23'];
        yield 'Number with scale=0' => [new Number('1'), '1'];
        yield 'Number with integer' => [new Number(123), '123'];
        yield 'GMP hex' => [new \GMP('0x10'), '16'];
        yield 'GMP base=10' => [new \GMP('10'), '10'];
    }

    /**
     * @dataProvider normalizeBadValueProvider
     */
    public function testNormalizeBadValueThrows(mixed $data)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The data must be an instance of "BcMath\Number" or "GMP".');

        $this->normalizer->normalize($data);
    }

    public static function normalizeBadValueProvider(): iterable
    {
        yield 'stdClass' => [new \stdClass()];
        yield 'string' => ['1.23'];
        yield 'null' => [null];
    }

    /**
     * @dataProvider supportsDenormalizationProvider
     */
    public function testSupportsDenormalization(mixed $data, string $type, bool $expected)
    {
        $this->assertSame($expected, $this->normalizer->supportsDenormalization($data, $type));
    }

    public static function supportsDenormalizationProvider(): iterable
    {
        yield 'null value, Number' => [null, Number::class, false];
        yield 'null value, GMP' => [null, \GMP::class, false];
        yield 'null value, unmatching type' => [null, \stdClass::class, false];
    }

    /**
     * @dataProvider denormalizeGoodValueProvider
     */
    public function testDenormalize(mixed $data, string $type, mixed $expected)
    {
        $this->assertEquals($expected, $this->normalizer->denormalize($data, $type));
    }

    public static function denormalizeGoodValueProvider(): iterable
    {
        yield 'Number, string with decimal point' => ['1.23', Number::class, new Number('1.23')];
        yield 'Number, integer as string' => ['123', Number::class, new Number('123')];
        yield 'Number, integer' => [123, Number::class, new Number('123')];
        yield 'GMP, large number' => ['9223372036854775808', \GMP::class, new \GMP('9223372036854775808')];
        yield 'GMP, integer' => [123, \GMP::class, new \GMP('123')];
    }

    /**
     * @dataProvider denormalizeBadValueProvider
     */
    public function testDenormalizeBadValueThrows(mixed $data, string $type, string $expectedException, string $expectedExceptionMessage)
    {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $this->normalizer->denormalize($data, $type);
    }

    public static function denormalizeBadValueProvider(): iterable
    {
        $stringOrDecimalExpectedMessage = 'The data must be a "string" representing a decimal number, or an "int".';
        yield 'Number, null' => [null, Number::class, NotNormalizableValueException::class, $stringOrDecimalExpectedMessage];
        yield 'Number, boolean' => [true, Number::class, NotNormalizableValueException::class, $stringOrDecimalExpectedMessage];
        yield 'Number, object' => [new \stdClass(), Number::class, NotNormalizableValueException::class, $stringOrDecimalExpectedMessage];
        yield 'Number, non-numeric string' => ['foobar', Number::class, NotNormalizableValueException::class, $stringOrDecimalExpectedMessage];
        yield 'Number, float' => [1.23, Number::class, NotNormalizableValueException::class, $stringOrDecimalExpectedMessage];

        $stringOrIntExpectedMessage = 'The data must be a "string" representing an integer, or an "int".';
        yield 'GMP, null' => [null, \GMP::class, NotNormalizableValueException::class, $stringOrIntExpectedMessage];
        yield 'GMP, boolean' => [true, \GMP::class, NotNormalizableValueException::class, $stringOrIntExpectedMessage];
        yield 'GMP, object' => [new \stdClass(), \GMP::class, NotNormalizableValueException::class, $stringOrIntExpectedMessage];
        yield 'GMP, non-numeric string' => ['foobar', \GMP::class, NotNormalizableValueException::class, $stringOrIntExpectedMessage];
        yield 'GMP, scale > 0' => ['1.23', \GMP::class, NotNormalizableValueException::class, $stringOrIntExpectedMessage];
        yield 'GMP, float' => [1.23, \GMP::class, NotNormalizableValueException::class, $stringOrIntExpectedMessage];

        yield 'unsupported type' => ['1.23', \stdClass::class, InvalidArgumentException::class, 'Only "BcMath\Number" and "GMP" types are supported.'];
    }
}
