<?php

namespace Tests\Unit;

use App\Services\ProductUnitConversionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProductUnitConversionTest extends TestCase
{
    public function test_it_converts_purchase_packages_into_stock_units(): void
    {
        $conversion = new ProductUnitConversionService();

        $this->assertSame('150.00000000', $conversion->quantityInStockUnit('3', '50'));
        $this->assertSame('1.60000000', $conversion->pricePerStockUnit('80', '50'));
    }

    public function test_it_preserves_fractional_stock_quantities(): void
    {
        $conversion = new ProductUnitConversionService();

        $this->assertSame('0.62500000', $conversion->quantityInStockUnit('1.25', '0.5'));
    }

    public function test_it_rejects_a_non_positive_factor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProductUnitConversionService())->quantityInStockUnit('1', '0');
    }
}