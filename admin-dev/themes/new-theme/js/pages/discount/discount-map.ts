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
const discountContainer = '.discount-container';

export default {
  currencySelect: '#discount_value_reduction_currency',
  currencySelectContainer: '.price-reduction-currency-selector',
  discountContainer,
  includeTaxInput: '#discount_value_reduction_include_tax',
  reductionTypeSelect: '#discount_value_reduction_type',
  reductionValueSymbol: `${discountContainer} .price-reduction-value .input-group .input-group-append .input-group-text,
   .price-reduction-value .input-group .input-group-prepend .input-group-text`,
  freeGiftProductSearchContainer: '#discount_free_gift',
  discountTypeRadios: '#discount_type_selector_discount_type_selector input[type="radio"]',
  discountTypeSubmit: '#discountTypeSubmit',
  specificProductsSearchContainer: '#discount_conditions_cart_conditions_specific_products',
  specificProductItem: '.specific-product-item',
  specificProductId: '.specific-product-id',
  specificProductType: '.specific-product-type',
  specificCombinationId: '.specific-combination-choice',
  carriersSelect: '#discount_conditions_delivery_conditions_carriers',
  countriesSelect: '#discount_conditions_delivery_conditions_country',
  categoryTree: '#discount_conditions_cart_conditions_product_segment_category',
};
