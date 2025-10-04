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

namespace PrestaShop\PrestaShop\Adapter\Discount\CommandHandler;

use PrestaShop\PrestaShop\Adapter\CartRule\CartRuleBuilder;
use PrestaShop\PrestaShop\Adapter\Discount\Repository\DiscountRepository;
use PrestaShop\PrestaShop\Adapter\Discount\Validate\DiscountValidator;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\Discount\Command\AddDiscountCommand;
use PrestaShop\PrestaShop\Core\Domain\Discount\CommandHandler\AddDiscountHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\Discount\Exception\DiscountConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Discount\ValueObject\DiscountId;

#[AsCommandHandler]
class AddDiscountHandler implements AddDiscountHandlerInterface
{
    public function __construct(
        private readonly DiscountRepository $discountRepository,
        private readonly CartRuleBuilder $cartRuleBuilder,
        private readonly DiscountValidator $discountValidator,
    ) {
    }

    /**
     * @throws DiscountConstraintException
     */
    public function handle(AddDiscountCommand $command): DiscountId
    {
        $discountType = $command->getDiscountType()->getValue();
        $this->discountValidator->validateDiscountPropertiesForType($discountType, $command);
        $BuiltCartRule = $this->cartRuleBuilder->build($command);
        $discount = $this->discountRepository->add($BuiltCartRule);

        return new DiscountId((int) $discount->id);
    }
}
