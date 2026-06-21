<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
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


use BaksDev\Products\Product\Repository\CurrentProductIdentifier\CurrentProductIdentifierByConstInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\CurrentQuantityByEventInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Modification\CurrentQuantityByModificationInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Offer\CurrentQuantityByOfferInterface;
use BaksDev\Products\Product\Repository\CurrentQuantity\Variation\CurrentQuantityByVariationInterface;
use BaksDev\Products\Product\Type\Offers\ConstId\ProductOfferConst;
use BaksDev\Products\Product\Type\Offers\Id\ProductOfferUid;
use BaksDev\Products\Product\Type\Offers\Variation\ConstId\ProductVariationConst;
use BaksDev\Products\Product\Type\Offers\Variation\Id\ProductVariationUid;
use BaksDev\Products\Product\Type\Offers\Variation\Modification\ConstId\ProductModificationConst;
use BaksDev\Products\Product\Type\Offers\Variation\Modification\Id\ProductModificationUid;
use BaksDev\Products\Stocks\Repository\ProductStocksTotal\ProductStocksTotalInterface;
use BaksDev\Products\Stocks\Repository\ProductStocksTotalByReserve\ProductStocksTotalByReserveInterface;
use BaksDev\Products\Stocks\Repository\VerifyByProfile\ProductStocksIncoming\ProductStocksIncomingVerifyInterface;
use BaksDev\Products\Stocks\Repository\VerifyByProfile\ProductStocksMove\ProductStocksMoveVerifyInterface;
use BaksDev\Products\Stocks\Repository\VerifyByProfile\ProductStocksOrders\ProductStocksIncomingOrdersInterface;
use BaksDev\Products\Stocks\Repository\VerifyByProfile\ProductStocksOrdersReserve\ProductStocksOrdersReserveVerifyInterface;
use BaksDev\Products\Stocks\Repository\VerifyByProfile\ProductStocksReserve\ProductStocksReserveVerifyInterface;
use BaksDev\Products\Stocks\Repository\VerifyByProfile\ProductStocksTotal\ProductStocksTotalVerifyInterface;
use BaksDev\Users\Profile\UserProfile\Repository\CurrentAllUserProfiles\CurrentAllUserProfilesByUserInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\User\Type\Id\UserUid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'baks:products-stocks:verify:stocks',
    description: 'Сверяем все транзакции c остатками'
)]
class ProductStocksVerifyCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private CurrentProductIdentifierByConstInterface $CurrentProductIdentifierByConstRepository,
        private CurrentAllUserProfilesByUserInterface $CurrentAllUserProfilesByUserRepository,
        private ProductStocksTotalVerifyInterface $ProductStocksTotalByProfileVerifyRepository,
        private ProductStocksIncomingVerifyInterface $ProductStocksIncomingVerifyRepository,
        private ProductStocksMoveVerifyInterface $ProductStocksMoveVerifyRepository,
        private ProductStocksIncomingOrdersInterface $ProductStocksIncomingOrdersRepository,
        private ProductStocksOrdersReserveVerifyInterface $ProductStocksOrdersReserveVerifyRepository,
        private ProductStocksTotalInterface $ProductStocksTotalRepository,
        private ProductStocksTotalByReserveInterface $ProductStocksTotalByReserveRepository,
        #[Autowire(env: 'PROJECT_USER')] private string|null $projectUser = null,
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
        $this->io = new SymfonyStyle($input, $output);

        if(empty($this->projectUser))
        {
            $this->io->warning('Не указан идентификатор пользователя проекта');

            return Command::SUCCESS;
        }

        /** Получаем все профили пользователя */

        $profiles = $this->CurrentAllUserProfilesByUserRepository
            ->forUser(new UserUid($this->projectUser))
            ->findAll();


        if(false === $profiles || false === $profiles->valid())
        {
            $this->io->warning(sprintf('%s: Профили пользователя проекта проекта не найдены', $this->projectUser));
            return Command::SUCCESS;
        }

        $profiles = iterator_to_array($profiles);
        $helper = $this->getHelper('question');

        /**
         * Интерактивная форма списка профилей
         */

        $questions[] = 'Все';

        foreach($profiles as $key => $quest)
        {
            $key++;
            $questions[$key] = $quest->getParams()->username;
        }

        $questions['-'] = 'Выйти';

        /** Объявляем вопрос с вариантами ответов */
        $question = new ChoiceQuestion(
            question: 'Профиль пользователя',
            choices: $questions,
            default: 0,
        );

        $key = $helper->ask($input, $output, $question);


        if(empty($key))
        {
            /** @var UserProfileUid $profile */
            foreach($profiles as $profile)
            {
                $key++;
                $this->io->success(sprintf('[%s] %s', $key, $profile->getParams()->username));
                $this->update($profile, $input->getOption('article'));
            }

            return Command::SUCCESS;
        }


        $UserProfileUid = null;

        foreach($profiles as $profile)
        {
            if($profile->getParams()->username === $questions[$key])
            {
                /* Присваиваем профиль пользователя */
                $UserProfileUid = $profile;
                break;
            }
        }

        if($UserProfileUid)
        {
            $this->io->success(sprintf('[%s] %s', $key, $UserProfileUid->getParams()->username));
            $this->update($UserProfileUid, $input->getOption('article'));
        }

        return Command::SUCCESS;
    }


    public function update(UserProfileUid $profile, ?string $article = null): void
    {
        /**
         * Получаем всю имеющуюся продукцию по складу текущего профиля
         */
        $resultStocks = $this->ProductStocksTotalByProfileVerifyRepository
            ->forProfile($profile)
            ->findAll();

        if(empty($resultStocks))
        {
            return;
        }

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
                $this->io->writeln(sprintf('<fg=gray>... %s</>', $CurrentProductIdentifierResult->getArticle()));
                continue;
            }

            /**
             * Получаем ОСТАТКИ на складе
             */

            /** Получаем остаток продукции на складе */
            $stockTotal = $this->ProductStocksTotalRepository
                ->forProfile($profile)
                ->product($ProductStocksTotalVerifyResult->getProduct())
                ->offer($ProductStocksTotalVerifyResult->getProductOfferConst())
                ->variation($ProductStocksTotalVerifyResult->getProductVariationConst())
                ->modification($ProductStocksTotalVerifyResult->getProductModificationConst())
                ->get();

            /** Получаем все ПРИХОДЫ на продукт */
            $incomingTotal = $this->ProductStocksIncomingVerifyRepository
                ->forProfile($profile)
                ->forProduct($ProductStocksTotalVerifyResult->getProduct())
                ->forOfferConst($ProductStocksTotalVerifyResult->getProductOfferConst())
                ->forVariationConst($ProductStocksTotalVerifyResult->getProductVariationConst())
                ->forModificationConst($ProductStocksTotalVerifyResult->getProductModificationConst())
                ->find();


            /** Получаем все РАСХОДЫ по заказам на продукт */
            $ordersTotal = $this->ProductStocksIncomingOrdersRepository
                ->forProfile($profile)
                ->forProduct($ProductStocksTotalVerifyResult->getProduct())
                ->forOfferConst($ProductStocksTotalVerifyResult->getProductOfferConst())
                ->forVariationConst($ProductStocksTotalVerifyResult->getProductVariationConst())
                ->forModificationConst($ProductStocksTotalVerifyResult->getProductModificationConst())
                ->find();


            /** Получаем все ПЕРЕМЕЩЕНИЯ (РАСХОДЫ) по продукту на другой склад */
            $moveTotal = $this->ProductStocksMoveVerifyRepository
                ->forProfile($profile)
                ->forProduct($ProductStocksTotalVerifyResult->getProduct())
                ->forOfferConst($ProductStocksTotalVerifyResult->getProductOfferConst())
                ->forVariationConst($ProductStocksTotalVerifyResult->getProductVariationConst())
                ->forModificationConst($ProductStocksTotalVerifyResult->getProductModificationConst())
                ->move();


            /**
             * Результат вычислений ОСТАТКОВ на складе
             */

            $total = $incomingTotal;

            if($ordersTotal)
            {
                $total -= $ordersTotal;
            }

            if($moveTotal)
            {
                $total -= $moveTotal;
            }


            if($stockTotal !== $total)
            {
                /** Получаем артикул для сверки */

                $this->io->text(sprintf(
                    '<fg=bright-red>%s : остаток на складе %s => расчетный %s</>',
                    $CurrentProductIdentifierResult->getArticle(),
                    $stockTotal,
                    $total,
                ));

                if($CurrentProductIdentifierResult->getArticle() === $article)
                {
                    break;
                }

                continue;
            }

            /**
             * Проверяем РЕЗЕРВЫ на складе
             */

            /** Получаем резерв продукта на складе  */
            $stockReserve = $this->ProductStocksTotalByReserveRepository
                ->forProfile($profile)
                ->product($ProductStocksTotalVerifyResult->getProduct())
                ->offer($ProductStocksTotalVerifyResult->getProductOfferConst())
                ->variation($ProductStocksTotalVerifyResult->getProductVariationConst())
                ->modification($ProductStocksTotalVerifyResult->getProductModificationConst())
                ->get();

            /** Получаем все резервы продукта по заказам */
            $ordersReserve = $this->ProductStocksOrdersReserveVerifyRepository
                ->forProfile($profile)
                ->forProduct($ProductStocksTotalVerifyResult->getProduct())
                ->forOfferConst($ProductStocksTotalVerifyResult->getProductOfferConst())
                ->forVariationConst($ProductStocksTotalVerifyResult->getProductVariationConst())
                ->forModificationConst($ProductStocksTotalVerifyResult->getProductModificationConst())
                ->find();

            /** Получаем все заявки на перемещения (резервы) */

            $moveReserve = $this->ProductStocksMoveVerifyRepository
                ->forProfile($profile)
                ->forProduct($ProductStocksTotalVerifyResult->getProduct())
                ->forOfferConst($ProductStocksTotalVerifyResult->getProductOfferConst())
                ->forVariationConst($ProductStocksTotalVerifyResult->getProductVariationConst())
                ->forModificationConst($ProductStocksTotalVerifyResult->getProductModificationConst())
                ->reserve();

            $ordersReserve += $moveReserve;

            if($stockReserve !== $ordersReserve)
            {

                /** Если резерв на складе больше расчетного - резерв некорректен */
                $format = ($stockReserve > $ordersReserve)
                    ? '<fg=bright-red>%s : резерв на складе %s => расчетный %s</>'
                    : '%s : резерв на складе %s => расчетный %s';

                $this->io->text(sprintf(
                    $format,
                    $CurrentProductIdentifierResult->getArticle(),
                    $stockReserve,
                    $ordersReserve,
                ));

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
    }
}
