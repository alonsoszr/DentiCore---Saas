<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Importe exacto en soles (SDD DI-05, RNF-001): BigDecimal con escala 2 y redondeo
 * HALF_UP. Inmutable; nunca usa float. Se serializa como cadena "1234.56" (RNF-046).
 */
final class Money implements JsonSerializable, Stringable
{
    public const SCALE = 2;

    private function __construct(private readonly BigDecimal $amount) {}

    /**
     * @throws InvalidArgumentException si recibe un float (pierde exactitud) o un texto no numérico.
     */
    public static function of(self|BigNumber|int|string|float $amount): self
    {
        if ($amount instanceof self) {
            return $amount;
        }

        if (is_float($amount)) {
            throw new InvalidArgumentException('Los importes no se aceptan como float; use una cadena decimal.');
        }

        try {
            return new self(BigDecimal::of($amount)->toScale(self::SCALE, RoundingMode::HalfUp));
        } catch (MathException $exception) {
            throw new InvalidArgumentException("Importe no válido: {$amount}", previous: $exception);
        }
    }

    public static function zero(): self
    {
        return self::of(0);
    }

    public function plus(self|BigNumber|int|string|float $other): self
    {
        return self::of($this->amount->plus(self::of($other)->amount));
    }

    public function minus(self|BigNumber|int|string|float $other): self
    {
        return self::of($this->amount->minus(self::of($other)->amount));
    }

    /**
     * Multiplica por una cantidad o factor exacto (p. ej. "3" o "0.18") y redondea HALF_UP.
     */
    public function multipliedBy(BigNumber|int|string|float $factor): self
    {
        return self::of($this->amount->multipliedBy(self::exact($factor)));
    }

    /**
     * Divide por un número exacto y redondea HALF_UP a 2 decimales.
     */
    public function dividedBy(BigNumber|int|string|float $divisor): self
    {
        return new self($this->amount->dividedBy(self::exact($divisor), self::SCALE, RoundingMode::HalfUp));
    }

    /**
     * Los float se rechazan también como factor: no representan decimales exactos.
     */
    private static function exact(BigNumber|int|string|float $number): BigNumber|int|string
    {
        if (is_float($number)) {
            throw new InvalidArgumentException('Los factores no se aceptan como float; use una cadena decimal.');
        }

        return $number;
    }

    public function compareTo(self $other): int
    {
        return $this->amount->compareTo($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->amount->isEqualTo($other->amount);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
