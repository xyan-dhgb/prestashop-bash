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

namespace PrestaShopBundle\Form\Admin\Sell\Discount;

use PrestaShop\PrestaShop\Core\ConstraintValidator\Constraints\DiscountProductSegment;
use PrestaShopBundle\Form\Admin\Type\EntitySearchInputType;
use PrestaShopBundle\Form\Admin\Type\ProductSearchType;
use PrestaShopBundle\Form\Admin\Type\ToggleChildrenChoiceType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\When;

class CartConditionsType extends TranslatorAwareType
{
    public const MINIMUM_AMOUNT = 'minimum_amount';
    public const MINIMUM_PRODUCT_QUANTITY = 'minimum_product_quantity';
    public const SPECIFIC_PRODUCTS = 'specific_products';
    public const PRODUCT_SEGMENT = 'product_segment';

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        parent::buildForm($builder, $options);
        $builder
            ->add(self::MINIMUM_AMOUNT, MinimumAmountType::class, [
                'label' => $this->trans('Minimum purchase amount', 'Admin.Catalog.Feature'),
                'required' => false,
                'constraints' => [
                    new When(
                        expression: sprintf(
                            'this.getParent().getParent().get("children_selector").getData() === "%s" && this.getParent().get("children_selector").getData() === "%s"',
                            DiscountConditionsType::CART_CONDITIONS,
                            self::MINIMUM_AMOUNT
                        ),
                        constraints: new Collection(
                            fields: [
                                'value' => new GreaterThan(0),
                            ],
                            allowExtraFields: true,
                        ),
                    ),
                ],
            ])
            ->add(self::MINIMUM_PRODUCT_QUANTITY, IntegerType::class, [
                'label' => $this->trans('Minimum product quantity', 'Admin.Catalog.Feature'),
                'required' => false,
                'default_empty_data' => 0,
                'constraints' => [
                    new When(
                        expression: sprintf(
                            'this.getParent().getParent().get("children_selector").getData() === "%s" && this.getParent().get("children_selector").getData() === "%s"',
                            DiscountConditionsType::CART_CONDITIONS,
                            self::MINIMUM_PRODUCT_QUANTITY
                        ),
                        constraints: [
                            new GreaterThan(0),
                        ],
                    ),
                ],
            ])
            ->add(self::SPECIFIC_PRODUCTS, ProductSearchType::class, [
                'layout' => EntitySearchInputType::LIST_LAYOUT,
                'entry_type' => SpecificProductType::class,
                'limit' => 0,
                'label' => $this->trans('Specific products', 'Admin.Catalog.Feature'),
                'include_combinations' => false,
                'required' => false,
                'constraints' => [
                    new When(
                        expression: sprintf(
                            'this.getParent().getParent().get("children_selector").getData() === "%s" && this.getParent().get("children_selector").getData() === "%s"',
                            DiscountConditionsType::CART_CONDITIONS,
                            self::SPECIFIC_PRODUCTS
                        ),
                        constraints: [
                            new Count(
                                min: 1,
                                minMessage: $this->trans('You need to select at least one product.', 'Admin.Catalog.Notification'),
                            ),
                        ],
                    ),
                ],
            ])
            ->add(self::PRODUCT_SEGMENT, DiscountProductSegmentType::class, [
                'label' => $this->trans('Product segment', 'Admin.Catalog.Feature'),
                'constraints' => [
                    new When(
                        expression: sprintf(
                            'this.getParent().getParent().get("children_selector").getData() === "%s" && this.getParent().get("children_selector").getData() === "%s"',
                            DiscountConditionsType::CART_CONDITIONS,
                            self::PRODUCT_SEGMENT
                        ),
                        constraints: [
                            new DiscountProductSegment(),
                        ]
                    ),
                ],
            ])
        ;
    }

    public function getParent()
    {
        return ToggleChildrenChoiceType::class;
    }
}
