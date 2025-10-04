<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace Tests\Integration\Behaviour\Features\Context\Domain\Discount;

use Behat\Gherkin\Node\TableNode;
use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\Assert;
use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleValidityException;
use PrestaShop\PrestaShop\Core\Domain\Discount\Command\AddDiscountCommand;
use PrestaShop\PrestaShop\Core\Domain\Discount\Command\DeleteDiscountCommand;
use PrestaShop\PrestaShop\Core\Domain\Discount\Command\UpdateDiscountCommand;
use PrestaShop\PrestaShop\Core\Domain\Discount\Exception\DiscountConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Discount\Exception\DiscountException;
use PrestaShop\PrestaShop\Core\Domain\Discount\Exception\DiscountNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Discount\Query\GetDiscountForEditing;
use PrestaShop\PrestaShop\Core\Domain\Discount\QueryResult\DiscountForEditing;
use PrestaShop\PrestaShop\Core\Domain\Discount\ValueObject\DiscountId;
use PrestaShop\PrestaShop\Core\Domain\Discount\ValueObject\DiscountType;
use PrestaShop\PrestaShop\Core\Util\DateTime\DateTime as DateTimeUtil;
use RuntimeException;
use Tests\Integration\Behaviour\Features\Context\Domain\AbstractDomainFeatureContext;
use Tests\Integration\Behaviour\Features\Context\Util\NoExceptionAlthoughExpectedException;
use Tests\Integration\Behaviour\Features\Context\Util\PrimitiveUtils;

class DiscountFeatureContext extends AbstractDomainFeatureContext
{
    /**
     * @Then I should get error that discount field :field is invalid
     */
    public function assertDiscountInvalidField(string $field): void
    {
        $errorCode = match ($field) {
            'name' => DiscountConstraintException::INVALID_NAME,
            'gift_product' => DiscountConstraintException::INVALID_GIFT_PRODUCT,
            default => null,
        };

        $this->assertLastErrorIs(DiscountConstraintException::class, $errorCode);
    }

    /**
     * @Then I should get an error that the discount code is already used
     */
    public function assertDiscountCodeAlreadyUsed(): void
    {
        $this->assertLastErrorIs(DiscountConstraintException::class, DiscountConstraintException::NON_UNIQUE_CODE);
    }

    /**
     * @Then I should get an error that the discount is invalid
     */
    public function assertDiscountIsInvalid(): void
    {
        $this->assertLastErrorIs(CartRuleValidityException::class);
    }

    /**
     * @Then discount :discountReference should have the following properties:
     *
     * @param string $discountReference
     * @param TableNode $tableNode
     */
    public function assertDiscount(string $discountReference, TableNode $tableNode): void
    {
        try {
            // if discount already exists we assert all its expected properties
            $this->assertDiscountProperties(
                $this->getDiscountForEditing($discountReference),
                $this->localizeByRows($tableNode)
            );
        } catch (DiscountException $e) {
            $this->setLastException($e);
        }
    }

    /**
     * @When I create a :discountType discount :discountReference with following properties:
     *
     * @param string $discountReference
     * @param TableNode $node
     *
     * @throws DiscountConstraintException
     * @throws Exception
     */
    public function createDiscount(string $discountReference, string $discountType, TableNode $node): void
    {
        $data = $this->localizeByRows($node);
        $command = new AddDiscountCommand($discountType, $data['name']);

        if (isset($data['name'])) {
            $command->setLocalizedNames($data['name']);
        }
        if (isset($data['highlight'])) {
            $command->setHighlightInCart(PrimitiveUtils::castStringBooleanIntoBoolean($data['highlight']));
        }
        if (isset($data['allow_partial_use'])) {
            $command->setAllowPartialUse(PrimitiveUtils::castStringBooleanIntoBoolean($data['allow_partial_use']));
        }
        if (isset($data['priority'])) {
            $command->setPriority((int) $data['priority']);
        }
        if (isset($data['active'])) {
            $command->setActive(PrimitiveUtils::castStringBooleanIntoBoolean($data['active']));
        }
        if (isset($data['valid_from'])) {
            if (empty($data['valid_to'])) {
                throw new RuntimeException('When setting cart rule range "valid_from" and "valid_to" must be provided');
            }
            $command->setValidityDateRange(
                new DateTimeImmutable($data['valid_from']),
                new DateTimeImmutable($data['valid_to']),
            );
        }
        if (isset($data['total_quantity'])) {
            $command->setTotalQuantity((int) $data['total_quantity']);
        }

        if (isset($data['quantity_per_user'])) {
            $command->setQuantityPerUser((int) $data['quantity_per_user']);
        }

        $command->setDescription($data['description'] ?? '');
        if (!empty($data['code'])) {
            $command->setCode($data['code']);
        }

        if ($command->getDiscountType()->getValue() === DiscountType::CART_LEVEL
            || $command->getDiscountType()->getValue() === DiscountType::PRODUCT_LEVEL
            || $command->getDiscountType()->getValue() === DiscountType::ORDER_LEVEL
        ) {
            if (!empty($data['reduction_percent'])) {
                $command->setPercentDiscount(new DecimalNumber($data['reduction_percent']));
            }

            if (!empty($data['reduction_amount'])) {
                try {
                    $command->setAmountDiscount(
                        new DecimalNumber($data['reduction_amount']),
                        $this->getSharedStorage()->get($data['reduction_currency']),
                        PrimitiveUtils::castStringBooleanIntoBoolean($data['taxIncluded']),
                    );
                } catch (DiscountConstraintException $e) {
                    $this->setLastException($e);
                }
            }
        }

        if ($command->getDiscountType()->getValue() === DiscountType::PRODUCT_LEVEL) {
            if (!empty($data['reduction_product'])) {
                if ((int) $data['reduction_product'] === -1 || (int) $data['reduction_product'] === -2) {
                    $command->setReductionProduct((int) $data['reduction_product']);
                } else {
                    $command->setReductionProduct($this->getSharedStorage()->get($data['reduction_product']));
                }
            }
        }

        if ($command->getDiscountType()->getValue() === DiscountType::FREE_GIFT) {
            if (!empty($data['gift_product'])) {
                $command->setProductId($this->referenceToId($data['gift_product']));
            }

            if (!empty($data['gift_combination'])) {
                $command->setCombinationId($this->referenceToId($data['gift_combination']));
            }
        }

        try {
            /** @var DiscountId $discountId */
            $discountId = $this->getCommandBus()->handle($command);
            $this->getSharedStorage()->set($discountReference, $discountId->getValue());
        } catch (DiscountConstraintException $e) {
            $this->setLastException($e);
        }
    }

    /**
     * @When I update discount :discountType with the following properties:
     */
    public function editDiscount(string $discountReference, TableNode $node): void
    {
        $data = $this->localizeByRows($node);
        $discountId = $this->getSharedStorage()->get($discountReference);
        $command = new UpdateDiscountCommand($discountId);

        if (isset($data['name'])) {
            $command->setLocalizedNames($data['name']);
        }
        if (isset($data['highlight'])) {
            $command->setHighlightInCart(PrimitiveUtils::castStringBooleanIntoBoolean($data['highlight']));
        }
        if (isset($data['allow_partial_use'])) {
            $command->setAllowPartialUse(PrimitiveUtils::castStringBooleanIntoBoolean($data['allow_partial_use']));
        }
        if (isset($data['priority'])) {
            $command->setPriority((int) $data['priority']);
        }
        if (isset($data['active'])) {
            $command->setActive(PrimitiveUtils::castStringBooleanIntoBoolean($data['active']));
        }
        if (isset($data['valid_from'])) {
            $command->setValidFrom(new DateTimeImmutable($data['valid_from']));
        }
        if (isset($data['valid_to'])) {
            $command->setValidTo(new DateTimeImmutable($data['valid_to']));
        }
        if (isset($data['total_quantity'])) {
            $command->setTotalQuantity((int) $data['total_quantity']);
        }

        if (isset($data['quantity_per_user'])) {
            $command->setQuantityPerUser((int) $data['quantity_per_user']);
        }

        $command->setDescription($data['description'] ?? '');
        if (!empty($data['code'])) {
            $command->setCode($data['code']);
        }

        if (!empty($data['reduction_percent'])) {
            $command->setPercentDiscount(new DecimalNumber($data['reduction_percent']));
        }

        if (!empty($data['reduction_amount'])) {
            try {
                $command->setAmountDiscount(
                    new DecimalNumber($data['reduction_amount']),
                    $this->getSharedStorage()->get($data['reduction_currency']),
                    PrimitiveUtils::castStringBooleanIntoBoolean($data['taxIncluded']),
                );
            } catch (DiscountConstraintException $e) {
                $this->setLastException($e);
            }
        }

        if (!empty($data['reduction_product'])) {
            if ((int) $data['reduction_product'] === -1 || (int) $data['reduction_product'] === -2) {
                $command->setReductionProduct((int) $data['reduction_product']);
            } else {
                $command->setReductionProduct($this->getSharedStorage()->get($data['reduction_product']));
            }
        }

        if (!empty($data['gift_product'])) {
            $command->setProductId($this->referenceToId($data['gift_product']));
        }

        if (!empty($data['gift_combination'])) {
            $command->setCombinationId($this->referenceToId($data['gift_combination']));
        }

        try {
            /* @var DiscountId $discountId */
            $this->getCommandBus()->handle($command);
        } catch (DiscountConstraintException $e) {
            $this->setLastException($e);
        }
    }

    /**
     * @Then I delete discount :discountReference:
     */
    public function deleteDiscount(string $discountReference): void
    {
        $discountId = $this->getSharedStorage()->get($discountReference);
        $command = new DeleteDiscountCommand($discountId);

        $this->getCommandBus()->handle($command);
    }

    /**
     * @Then discount :discountReference should not exist anymore:
     */
    public function assertDiscountIsNotFound(string $discountReference): void
    {
        try {
            $this->getDiscountForEditing($discountReference);
            throw new NoExceptionAlthoughExpectedException(sprintf('Discount "%s" was found, but it was expected to be deleted', $discountReference));
        } catch (DiscountNotFoundException $e) {
            $this->getSharedStorage()->clear($discountReference);
        }
    }

    protected function assertDiscountProperties(DiscountForEditing $discountForEditing, array $expectedData): void
    {
        if (isset($expectedData['description'])) {
            Assert::assertSame($expectedData['description'], $discountForEditing->getDescription(), 'Unexpected description');
        }
        if (isset($expectedData['highlight'])) {
            Assert::assertSame(
                PrimitiveUtils::castStringBooleanIntoBoolean($expectedData['highlight']),
                $discountForEditing->isHighlightInCart(),
                'Unexpected highlight'
            );
        }
        if (isset($expectedData['allow_partial_use'])) {
            Assert::assertSame(
                PrimitiveUtils::castStringBooleanIntoBoolean($expectedData['allow_partial_use']),
                $discountForEditing->isAllowPartialUse(),
                'Unexpected partial use'
            );
        }
        if (isset($expectedData['active'])) {
            Assert::assertSame(
                PrimitiveUtils::castStringBooleanIntoBoolean($expectedData['active']),
                $discountForEditing->isActive(),
                'Unexpected active property'
            );
        }
        if (isset($expectedData['code'])) {
            Assert::assertSame($expectedData['code'], $discountForEditing->getCode(), 'Unexpected code');
        }
        if (isset($expectedData['customer'])) {
            Assert::assertSame(
                !empty($expectedData['customer']) ? $this->getSharedStorage()->get($expectedData['customer']) : 0,
                $discountForEditing->getCustomerId(),
                'Unexpected customer id'
            );
        }
        if (isset($expectedData['priority'])) {
            Assert::assertSame((int) $expectedData['priority'], $discountForEditing->getPriority(), 'Unexpected priority');
        }
        if (isset($expectedData['valid_from'])) {
            Assert::assertEquals(
                $expectedData['valid_from'],
                $discountForEditing->getValidFrom()->format(DateTimeUtil::DEFAULT_DATETIME_FORMAT),
                'Unexpected valid_from'
            );
        }
        if (isset($expectedData['valid_to'])) {
            Assert::assertEquals(
                $expectedData['valid_to'],
                $discountForEditing->getValidTo()->format(DateTimeUtil::DEFAULT_DATETIME_FORMAT),
                'Unexpected valid_to'
            );
        }
        if (isset($expectedData['total_quantity'])) {
            Assert::assertSame((int) $expectedData['total_quantity'], $discountForEditing->getTotalQuantity(), 'Unexpected quantity');
        }
        if (isset($expectedData['quantity_per_user'])) {
            Assert::assertSame((int) $expectedData['quantity_per_user'], $discountForEditing->getQuantityPerUser(), 'Unexpected quantity_per_user');
        }

        if (isset($expectedData['reduction_percent'])) {
            Assert::assertSame((float) $expectedData['reduction_percent'], (float) (string) $discountForEditing->getPercentDiscount(), 'Unexpected percent discount');
        }

        if (isset($expectedData['reduction_amount'])) {
            Assert::assertSame((float) $expectedData['reduction_amount'], (float) (string) $discountForEditing->getAmountDiscount(), 'Unexpected amount discount');
        }
        if (isset($expectedData['reduction_currency'])) {
            Assert::assertSame($this->getSharedStorage()->get($expectedData['reduction_currency']), $discountForEditing->getCurrencyId(), 'Unexpected reduction currency');
        }
        if (isset($expectedData['taxIncluded'])) {
            Assert::assertSame(PrimitiveUtils::castStringBooleanIntoBoolean($expectedData['taxIncluded']), $discountForEditing->isTaxIncluded(), 'Unexpected tax included');
        }
        if (isset($expectedData['type'])) {
            Assert::assertSame($expectedData['type'], $discountForEditing->getType()->getValue(), 'Unexpected type');
        }
        if (isset($expectedData['reduction_product'])) {
            if ((int) $expectedData['reduction_product'] === -1 || (int) $expectedData['reduction_product'] === -2) {
                Assert::assertSame((int) $expectedData['reduction_product'], $discountForEditing->getReductionProduct());
            } else {
                Assert::assertSame($this->getSharedStorage()->get($expectedData['reduction_product']), $discountForEditing->getReductionProduct());
            }
        }
        if (isset($expectedData['name'])) {
            Assert::assertSame($expectedData['name'], $discountForEditing->getLocalizedNames());
        }
        if (isset($expectedData['minimum_product_quantity'])) {
            Assert::assertEquals($expectedData['minimum_product_quantity'], $discountForEditing->getMinimumProductQuantity());
        }
        if (isset($expectedData['minimum_amount'])) {
            Assert::assertSame((float) $expectedData['minimum_amount'], (float) (string) $discountForEditing->getMinimumAmount(), 'Unexpected minimum amount');
        }
        if (isset($expectedData['minimum_amount_currency'])) {
            Assert::assertSame($this->getSharedStorage()->get($expectedData['minimum_amount_currency']), $discountForEditing->getMinimumAmountCurrencyId(), 'Unexpected minimum amount currency');
        }
        if (isset($expectedData['minimum_amount_tax_included'])) {
            Assert::assertSame(PrimitiveUtils::castStringBooleanIntoBoolean($expectedData['minimum_amount_tax_included']), $discountForEditing->getMinimumAmountTaxIncluded(), 'Unexpected minimum amount tax included');
        }
        if (isset($expectedData['minimum_amount_shipping_included'])) {
            Assert::assertSame(PrimitiveUtils::castStringBooleanIntoBoolean($expectedData['minimum_amount_shipping_included']), $discountForEditing->getMinimumAmountShippingIncluded(), 'Unexpected minimum amount shipping included');
        }
        if (isset($expectedData['carriers'])) {
            Assert::assertSame($this->referencesToIds($expectedData['carriers']), $discountForEditing->getCarrierIds(), 'Unexpected carriers');
        }
        if (isset($expectedData['countries'])) {
            Assert::assertSame($this->referencesToIds($expectedData['countries']), $discountForEditing->getCountryIds(), 'Unexpected countries');
        }
    }

    protected function getDiscountForEditing(string $discountReference): DiscountForEditing
    {
        /** @var DiscountForEditing $discountForEditing */
        $discountForEditing = $this->getQueryBus()->handle(
            new GetDiscountForEditing($this->getSharedStorage()->get($discountReference))
        );

        return $discountForEditing;
    }
}
