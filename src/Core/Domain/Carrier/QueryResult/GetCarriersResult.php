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

namespace PrestaShop\PrestaShop\Core\Domain\Carrier\QueryResult;

/**
 * Model returning available carriers as well as carriers that have been removed.
 */
class GetCarriersResult
{
    /**
     * @var CarrierSummary[]
     */
    private array $availableCarriers;

    /**
     * @var FilteredCarrier[]
     */
    private array $filteredCarrier;

    public function __construct(array $availableCarriers, array $filteredCarrier)
    {
        $this->availableCarriers = $availableCarriers;
        $this->filteredCarrier = $filteredCarrier;
    }

    /**
     * @return CarrierSummary[]
     */
    public function getAvailableCarriers(): array
    {
        return $this->availableCarriers;
    }

    /**
     * @return FilteredCarrier[]
     */
    public function getFilteredOutCarriers(): array
    {
        return $this->filteredCarrier;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function getAvailableCarriersToArray(): array
    {
        return array_map(function (CarrierSummary $carrier) { return $carrier->toArray(); }, $this->availableCarriers);
    }
}
