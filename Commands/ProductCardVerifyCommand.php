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

namespace BaksDev\Products\Stocks\Commands;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Elastic\BaksDevElasticBundle;
use BaksDev\Orders\Order\Repository\ProductTotalInOrders\ProductTotalInOrdersInterface;
use BaksDev\Products\Product\Entity\Offers\ProductOffer;
use BaksDev\Products\Product\Entity\Offers\Quantity\ProductOfferQuantity;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\ProductModification;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\Quantity\ProductModificationQuantity;
use BaksDev\Products\Product\Entity\Offers\Variation\ProductVariation;
use BaksDev\Products\Product\Entity\Offers\Variation\Quantity\ProductVariationQuantity;
use BaksDev\Products\Product\Entity\Price\ProductPrice;
use BaksDev\Products\Product\Entity\Product;
use BaksDev\Products\Product\Entity\Trans\ProductTrans;
use BaksDev\Products\Product\Repository\CurrentProductIdentifier\CurrentProductIdentifierByConstInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\CurrentQuantityByEventInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Modification\CurrentQuantityByModificationInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Offer\CurrentQuantityByOfferInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Variation\CurrentQuantityByVariationInterface;
use BaksDev\Products\Product\Type\Id\ProductUid;
use BaksDev\Products\Product\Type\Offers\ConstId\ProductOfferConst;
use BaksDev\Products\Product\Type\Offers\Id\ProductOfferUid;
use BaksDev\Products\Product\Type\Offers\Variation\ConstId\ProductVariationConst;
use BaksDev\Products\Product\Type\Offers\Variation\Id\ProductVariationUid;
use BaksDev\Products\Product\Type\Offers\Variation\Modification\ConstId\ProductModificationConst;
use BaksDev\Products\Product\Type\Offers\Variation\Modification\Id\ProductModificationUid;
use BaksDev\Products\Stocks\Repository\ProductStocksTotal\ProductStocksTotalInterface;
use BaksDev\Products\Stocks\Repository\ProductStocksTotalByReserve\ProductStocksTotalByReserveInterface;
use BaksDev\Products\Stocks\Repository\VerifyByProfile\ProductOrdersWaitReserve\ProductOrdersWaitReserveVerifyInterface;
use BaksDev\Products\Stocks\Repository\VerifyByProfile\ProductStocksTotal\ProductStocksTotalVerifyInterface;
use BaksDev\Users\Profile\UserProfile\Entity\Event\Warehouse\UserProfileWarehouse;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Repository\CurrentAllUserProfiles\CurrentAllUserProfilesByUserInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'baks:products-stocks:verify:card',
    description: 'Сверяем наличие в карточке товаров и на складе '
)]
class ProductCardVerifyCommand extends Command
{
    public function __construct(
        private CurrentProductIdentifierByConstInterface $CurrentProductIdentifierByConstRepository,
        private ProductStocksTotalVerifyInterface $ProductStocksTotalByProfileVerifyRepository,
        private ProductStocksTotalInterface $ProductStocksTotalRepository,
        private ProductStocksTotalByReserveInterface $ProductStocksTotalByReserveRepository,
        private CurrentQuantityByEventInterface $CurrentQuantityByEventRepository,
        private CurrentQuantityByOfferInterface $CurrentQuantityByOfferRepository,
        private CurrentQuantityByVariationInterface $CurrentQuantityByVariationRepository,
        private CurrentQuantityByModificationInterface $CurrentQuantityByModificationRepository,
        private ProductOrdersWaitReserveVerifyInterface $ProductOrdersWaitReserveVerifyRepository,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('article', 'a', InputOption::VALUE_OPTIONAL, 'Фильтр по артикулу ((--article=... || -a ...))');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /**
         * Получаем всю имеющуюся продукцию на складах
         */
        $resultStocks = $this->ProductStocksTotalByProfileVerifyRepository
            ->findAll();

        if(empty($resultStocks))
        {
            return Command::SUCCESS;
        }

        $article = $input->getOption('article');

        foreach($resultStocks as $ProductStocksTotalVerifyResult)
        {
            /** Получаем активные идентификаторы продукта */
            $CurrentProductIdentifierResult = $this
                ->CurrentProductIdentifierByConstRepository
                ->forProduct($ProductStocksTotalVerifyResult->getProduct())
                ->forOfferConst($ProductStocksTotalVerifyResult->getProductOfferConst())
                ->forVariationConst($ProductStocksTotalVerifyResult->getProductVariationConst())
                ->forModificationConst($ProductStocksTotalVerifyResult->getProductModificationConst())
                ->find();

            if(false === empty($article) && stripos($CurrentProductIdentifierResult->getArticle(), $article) === false)
            {
                $io->writeln(sprintf('<fg=gray>... %s</>', $CurrentProductIdentifierResult->getArticle()));
                continue;
            }

            /**
             * Проверяем карточку
             */


            /** Получаем ОСТАТОК в логистических складах */
            $logisticTotal = $this->ProductStocksTotalRepository
                ->onlyLogisticWarehouse()
                ->product($ProductStocksTotalVerifyResult->getProduct())
                ->offer($ProductStocksTotalVerifyResult->getProductOfferConst())
                ->variation($ProductStocksTotalVerifyResult->getProductVariationConst())
                ->modification($ProductStocksTotalVerifyResult->getProductModificationConst())
                ->get();

            /** Получаем РЕЗЕРВ в логистических складах */
            $logisticReserve = $this->ProductStocksTotalByReserveRepository
                ->onlyLogisticWarehouse()
                ->product($ProductStocksTotalVerifyResult->getProduct())
                ->offer($ProductStocksTotalVerifyResult->getProductOfferConst())
                ->variation($ProductStocksTotalVerifyResult->getProductVariationConst())
                ->modification($ProductStocksTotalVerifyResult->getProductModificationConst())
                ->get();


            $cardQuantity = match (true)
            {
                $CurrentProductIdentifierResult->getModification() instanceof ProductModificationUid =>
                $this->CurrentQuantityByModificationRepository->getModificationQuantity(
                    event: $CurrentProductIdentifierResult->getEvent(),
                    offer: $CurrentProductIdentifierResult->getOffer(),
                    variation: $CurrentProductIdentifierResult->getVariation(),
                    modification: $CurrentProductIdentifierResult->getModification(),
                ),

                $CurrentProductIdentifierResult->getVariation() instanceof ProductVariationUid =>
                $this->CurrentQuantityByVariationRepository->getVariationQuantity(
                    event: $CurrentProductIdentifierResult->getEvent(),
                    offer: $CurrentProductIdentifierResult->getOffer(),
                    variation: $CurrentProductIdentifierResult->getVariation(),
                ),

                $CurrentProductIdentifierResult->getOffer() instanceof ProductOfferUid =>
                $this->CurrentQuantityByOfferRepository->getOfferQuantity(
                    event: $CurrentProductIdentifierResult->getEvent(),
                    offer: $CurrentProductIdentifierResult->getOffer(),
                ),

                default => $this->CurrentQuantityByEventRepository->getQuantity(event: $CurrentProductIdentifierResult->getEvent())
            };

            if($cardQuantity->getQuantity() !== $logisticTotal)
            {

                $io->text(sprintf(
                    '<fg=bright-red>%s : остаток в карточке %s => расчетный %s</>',
                    $CurrentProductIdentifierResult->getArticle(),
                    $cardQuantity->getQuantity(),
                    $logisticTotal,
                ));

                if($CurrentProductIdentifierResult->getArticle() === $article)
                {
                    break;
                }

                continue;
            }

            if($cardQuantity->getReserve() !== $logisticReserve)
            {

                /** Получаем количество резерва на продукт по заказам не отправленным на склад */
                $waitReserve = $this->ProductOrdersWaitReserveVerifyRepository
                    ->forProduct($ProductStocksTotalVerifyResult->getProduct())
                    ->forOfferConst($ProductStocksTotalVerifyResult->getProductOfferConst())
                    ->forVariationConst($ProductStocksTotalVerifyResult->getProductVariationConst())
                    ->forModificationConst($ProductStocksTotalVerifyResult->getProductModificationConst())
                    ->find();

                if($cardQuantity->getReserve() !== ($logisticReserve + $waitReserve))
                {
                    $io->text(sprintf(
                        '%s : резерв в карточке %s => расчетный %s (еще в ожидании %s)',
                        $CurrentProductIdentifierResult->getArticle(),
                        $cardQuantity->getReserve(),
                        $logisticReserve,
                        $waitReserve,
                    ));
                }

                if($CurrentProductIdentifierResult->getArticle() === $article)
                {
                    break;
                }

                continue;
            }

            if($CurrentProductIdentifierResult->getArticle() === $article)
            {
                break;
            }

        }

        return Command::SUCCESS;
    }
}
