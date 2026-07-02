<?php
/*
 *  Copyright 2026.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Products\Stocks\Repository\VerifyByProfile\ProductOrdersWaitReserve;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\DeliveryTransport\Type\OrderStatus\OrderStatusDelivery;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Entity\Invariable\OrderInvariable;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Entity\Products\OrderProduct;
use BaksDev\Orders\Order\Entity\Products\Price\OrderPrice;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusExtradition;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusPackage;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusPhone;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusUnpaid;
use BaksDev\Products\Product\Entity\Offers\ProductOffer;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\ProductModification;
use BaksDev\Products\Product\Entity\Offers\Variation\ProductVariation;
use BaksDev\Products\Product\Entity\Product;
use BaksDev\Products\Product\Type\Id\ProductUid;
use BaksDev\Products\Product\Type\Offers\ConstId\ProductOfferConst;
use BaksDev\Products\Product\Type\Offers\Variation\ConstId\ProductVariationConst;
use BaksDev\Products\Product\Type\Offers\Variation\Modification\ConstId\ProductModificationConst;
use BaksDev\Users\Profile\UserProfile\Entity\Event\Warehouse\UserProfileWarehouse;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Doctrine\DBAL\ArrayParameterType;


final class ProductOrdersWaitReserveVerifyRepository implements ProductOrdersWaitReserveVerifyInterface
{
    private UserProfileUid|false $profile = false;

    private ProductUid $product;

    private ProductOfferConst|false $offerConst = false;

    private ProductVariationConst|false $variationConst = false;

    private ProductModificationConst|false $modificationConst = false;

    public function __construct(private readonly DBALQueryBuilder $DBALQueryBuilder) {}

    public function forProfile(UserProfileUid $profile): self
    {
        $this->profile = $profile;

        return $this;
    }

    public function forProduct(ProductUid $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function forOfferConst(ProductOfferConst|null|false $offerConst): self
    {
        if(empty($offerConst))
        {
            $this->offerConst = false;
            return $this;
        }

        $this->offerConst = $offerConst;

        return $this;
    }

    public function forVariationConst(ProductVariationConst|null|false $variationConst): self
    {
        if(empty($variationConst))
        {
            $this->variationConst = false;
            return $this;
        }

        $this->variationConst = $variationConst;

        return $this;
    }

    public function forModificationConst(ProductModificationConst|null|false $modificationConst): self
    {
        if(empty($modificationConst))
        {
            $this->modificationConst = false;
            return $this;
        }

        $this->modificationConst = $modificationConst;

        return $this;
    }

    /**
     * Получаем количество резерва на продукт по заказам:
     * - New «Новый»
     * - Phone «Не дозвонились»
     * - Unpaid «В ожидании оплаты»
     *
     * если у заказа имеется профиль склада - он должен быть логистическим
     */
    public function find(): int
    {
        $dbal = $this->DBALQueryBuilder->createQueryBuilder(self::class);

        $dbal->from(Order::class, 'main');

        $dbal->join(
            'main',
            OrderInvariable::class,
            'orders_invariable',
            '
                orders_invariable.main = main.id
            '.($this->profile instanceof UserProfileUid ? ' AND orders_invariable.profile = :profile' : ''),
        );

        if($this->profile instanceof UserProfileUid)
        {
            $dbal->setParameter(
                key: 'profile',
                value: $this->profile,
                type: UserProfileUid::TYPE,
            );
        }


        $dbal->join(
            'main',
            OrderEvent::class,
            'orders_event',
            'orders_event.id = main.event AND orders_event.status IN (:status)',
        )
            ->setParameter(
                key: 'status',
                value: [
                    OrderStatusNew::STATUS,
                    OrderStatusPhone::STATUS,
                    OrderStatusUnpaid::STATUS,
                ],
                type: ArrayParameterType::STRING,
            );

        $dbal->join(
            'main',
            OrderProduct::class,
            'orders_product',
            'orders_product.event = main.event',
        );

        $dbal
            ->join(
                'main',
                Product::class,
                'product',
                'product.id = :product',
            )
            ->setParameter(
                key: 'product',
                value: $this->product,
                type: ProductUid::TYPE,
            );

        if($this->offerConst instanceof ProductOfferConst)
        {
            $dbal
                ->join(
                    'product',
                    ProductOffer::class,
                    'product_offer',
                    'product_offer.id = orders_product.offer 
                    AND product_offer.const = :offer_const',
                )
                ->setParameter(
                    key: 'offer_const',
                    value: $this->offerConst,
                    type: ProductOfferConst::TYPE,
                );


            if($this->variationConst instanceof ProductVariationConst)
            {
                $dbal
                    ->join(
                        'product',
                        ProductVariation::class,
                        'product_variation',
                        '
                            product_variation.id = orders_product.variation 
                            AND product_variation.const = :variation_const
                        ',
                    )
                    ->setParameter(
                        key: 'variation_const',
                        value: $this->variationConst,
                        type: ProductVariationConst::TYPE,
                    );


                if($this->modificationConst instanceof ProductModificationConst)
                {
                    $dbal
                        ->join(
                            'product',
                            ProductModification::class,
                            'product_modification',
                            '
                                product_modification.id = orders_product.modification 
                                AND product_modification.const = :modification_const
                            ',
                        )
                        ->setParameter(
                            key: 'modification_const',
                            value: $this->modificationConst,
                            type: ProductModificationConst::TYPE,
                        );
                }
            }
        }

        $dbal->leftJoin(
            'orders_invariable',
            UserProfile::class,
            'profile',
            'profile.id = orders_invariable.profile',
        );

        $dbal->leftJoin(
            'profile',
            UserProfileWarehouse::class,
            'profile_warehouse',
            'profile_warehouse.event = profile.event',
        );

        $dbal->andWhere('profile_warehouse.value IS TRUE');

        $dbal
            ->select('SUM(orders_price.total)')
            ->leftJoin(
                'orders_product',
                OrderPrice::class,
                'orders_price',
                'orders_price.product = orders_product.id',
            );


        $result = $dbal->fetchOne() ?: 0;

        $this->profile = false;

        return $result;

    }
}