<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class ProductUnitConversionService
{
    public function quantityInStockUnit(string|int|float $quantity, string|int|float $factor): string
    {
        $quantityValue = BigDecimal::of((string) $quantity);
        $factorValue = $this->positiveFactor($factor);

        if (! $quantityValue->isGreaterThan(BigDecimal::of('0'))) {
            throw new InvalidArgumentException('La quantité doit être supérieure à zéro.');
        }

        return $quantityValue->multipliedBy($factorValue)->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    public function pricePerStockUnit(string|int|float $unitPrice, string|int|float $factor): string
    {
        return BigDecimal::of((string) $unitPrice)
            ->dividedBy($this->positiveFactor($factor), 8, RoundingMode::HALF_UP)
            ->__toString();
    }

    private function positiveFactor(string|int|float $factor): BigDecimal
    {
        $value = BigDecimal::of((string) $factor);

        if (! $value->isGreaterThan(BigDecimal::of('0'))) {
            throw new InvalidArgumentException('Le facteur de conversion doit être supérieur à zéro.');
        }

        return $value;
    }
}