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

namespace PrestaShopBundle\Controller\Admin\Sell\Order;

use Currency;
use Exception;
use InvalidArgumentException;
use PrestaShop\PrestaShop\Adapter\Currency\CurrencyDataProvider;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PrestaShop\PrestaShop\Adapter\Order\Repository\OrderDetailRepository;
use PrestaShop\PrestaShop\Adapter\PDF\OrderInvoicePdfGenerator;
use PrestaShop\PrestaShop\Adapter\Tools;
use PrestaShop\PrestaShop\Core\Action\ActionsBarButtonsCollection;
use PrestaShop\PrestaShop\Core\Domain\Cart\Query\GetCartForOrderCreation;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\InvalidCartRuleDiscountValueException;
use PrestaShop\PrestaShop\Core\Domain\CustomerMessage\Command\AddOrderCustomerMessageCommand;
use PrestaShop\PrestaShop\Core\Domain\CustomerMessage\Exception\CannotSendEmailException;
use PrestaShop\PrestaShop\Core\Domain\CustomerMessage\Exception\CustomerMessageConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\AddCartRuleToOrderCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\BulkChangeOrderStatusCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\ChangeOrderCurrencyCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\ChangeOrderDeliveryAddressCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\ChangeOrderInvoiceAddressCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\DeleteCartRuleFromOrderCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\DuplicateOrderCartCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\ResendOrderEmailCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\SendProcessOrderEmailCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\SetInternalOrderNoteCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\UpdateOrderShippingDetailsCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\UpdateOrderStatusCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\CannotEditDeliveredOrderProductException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\CannotFindProductInOrderException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\ChangeOrderStatusException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\DuplicateProductInOrderException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\DuplicateProductInOrderInvoiceException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\InvalidAmountException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\InvalidCancelProductException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\InvalidOrderStateException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\InvalidProductQuantityException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\NegativePaymentAmountException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\OrderConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\OrderEmailSendException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\OrderException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\OrderNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\TransistEmailSendingException;
use PrestaShop\PrestaShop\Core\Domain\Order\Invoice\Command\GenerateInvoiceCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Invoice\Command\UpdateInvoiceNoteCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Invoice\Exception\InvoiceException;
use PrestaShop\PrestaShop\Core\Domain\Order\OrderConstraints;
use PrestaShop\PrestaShop\Core\Domain\Order\Payment\Command\AddPaymentCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Product\Command\AddProductToOrderCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Product\Command\DeleteProductFromOrderCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Product\Command\UpdateProductInOrderCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Query\GetOrderForViewing;
use PrestaShop\PrestaShop\Core\Domain\Order\Query\GetOrderPreview;
use PrestaShop\PrestaShop\Core\Domain\Order\QueryResult\OrderForViewing;
use PrestaShop\PrestaShop\Core\Domain\Order\QueryResult\OrderPreview;
use PrestaShop\PrestaShop\Core\Domain\Order\QueryResult\OrderProductForViewing;
use PrestaShop\PrestaShop\Core\Domain\Order\ValueObject\OrderId;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\ProductOutOfStockException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\ProductSearchEmptyPhraseException;
use PrestaShop\PrestaShop\Core\Domain\Product\Query\SearchProducts;
use PrestaShop\PrestaShop\Core\Domain\Product\QueryResult\FoundProduct;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Command\EditShipment;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Command\MergeProductsToShipment;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Command\SplitShipment;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Exception\ShipmentException;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Query\GetOrderShipments;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Query\GetShipmentForEditing;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Query\GetShipmentProducts;
use PrestaShop\PrestaShop\Core\Domain\Shipment\QueryResult\OrderShipment;
use PrestaShop\PrestaShop\Core\Domain\Shipment\QueryResult\OrderShipmentProduct;
use PrestaShop\PrestaShop\Core\Domain\Shipment\ValueObject\OrderDetailId;
use PrestaShop\PrestaShop\Core\Domain\ValueObject\QuerySorting;
use PrestaShop\PrestaShop\Core\Exception\CoreException;
use PrestaShop\PrestaShop\Core\FeatureFlag\FeatureFlagSettings;
use PrestaShop\PrestaShop\Core\FeatureFlag\FeatureFlagStateCheckerInterface;
use PrestaShop\PrestaShop\Core\Form\ChoiceProvider\LanguageByIdChoiceProvider;
use PrestaShop\PrestaShop\Core\Form\ConfigurableFormChoiceProviderInterface;
use PrestaShop\PrestaShop\Core\Form\FormChoiceProviderInterface;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Builder\FormBuilderInterface;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Handler\FormHandlerInterface;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\OrderGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\GridFactory;
use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShop\PrestaShop\Core\Kpi\Row\KpiRowFactoryInterface;
use PrestaShop\PrestaShop\Core\Order\OrderSiblingProviderInterface;
use PrestaShop\PrestaShop\Core\PDF\PDFGeneratorInterface;
use PrestaShop\PrestaShop\Core\Search\Filters\OrderFilters;
use PrestaShop\PrestaShop\Core\Search\Filters\ShipmentFilters;
use PrestaShopBundle\Component\CsvResponse;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Exception\InvalidModuleException;
use PrestaShopBundle\Form\Admin\Sell\Customer\PrivateNoteType;
use PrestaShopBundle\Form\Admin\Sell\Order\AddOrderCartRuleType;
use PrestaShopBundle\Form\Admin\Sell\Order\AddProductRowType;
use PrestaShopBundle\Form\Admin\Sell\Order\CartSummaryType;
use PrestaShopBundle\Form\Admin\Sell\Order\ChangeOrderAddressType;
use PrestaShopBundle\Form\Admin\Sell\Order\ChangeOrderCurrencyType;
use PrestaShopBundle\Form\Admin\Sell\Order\ChangeOrdersStatusType;
use PrestaShopBundle\Form\Admin\Sell\Order\EditProductRowType;
use PrestaShopBundle\Form\Admin\Sell\Order\InternalNoteType;
use PrestaShopBundle\Form\Admin\Sell\Order\OrderMessageType;
use PrestaShopBundle\Form\Admin\Sell\Order\OrderPaymentType;
use PrestaShopBundle\Form\Admin\Sell\Order\Shipment\EditShipmentType;
use PrestaShopBundle\Form\Admin\Sell\Order\Shipment\MergeShipmentType;
use PrestaShopBundle\Form\Admin\Sell\Order\Shipment\SplitShipmentType;
use PrestaShopBundle\Form\Admin\Sell\Order\UpdateOrderShippingType;
use PrestaShopBundle\Form\Admin\Sell\Order\UpdateOrderStatusType;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use PrestaShopBundle\Security\Attribute\DemoRestricted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * Manages "Sell > Orders" page
 */
class OrderController extends PrestaShopAdminController
{
    /**
     * Default number of products per page (in case invalid value is used)
     */
    public const DEFAULT_PRODUCTS_NUMBER = 8;

    /**
     * Options used for the number of products per page
     */
    public const PRODUCTS_PAGINATION_OPTIONS = [8, 20, 50, 100];

    public function __construct(private readonly FormFactoryInterface $formFactory)
    {
    }

    /**
     * Shows list of orders
     *
     * @param Request $request
     * @param OrderFilters $filters
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function indexAction(
        Request $request,
        OrderFilters $filters,
        #[Autowire(service: 'prestashop.core.kpi_row.factory.orders')] KpiRowFactoryInterface $orderKpiFactory,
        #[Autowire(service: 'prestashop.core.grid.factory.order')] GridFactory $orderGridFactory,
    ) {
        $orderGrid = $orderGridFactory->getGrid($filters);

        $changeOrderStatusesForm = $this->createForm(ChangeOrdersStatusType::class);

        return $this->render(
            '@PrestaShop/Admin/Sell/Order/Order/index.html.twig',
            [
                'orderGrid' => $this->presentGrid($orderGrid),
                'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
                'enableSidebar' => true,
                'changeOrderStatusesForm' => $changeOrderStatusesForm->createView(),
                'orderKpi' => $orderKpiFactory->build(),
                'layoutHeaderToolbarBtn' => $this->getOrderToolbarButtons(),
            ]
        );
    }

    /**
     * @return array
     */
    private function getOrderToolbarButtons(): array
    {
        $toolbarButtons = [];

        $isSingleShopContext = $this->getShopContext()->getShopConstraint()->isSingleShopContext();

        $toolbarButtons['add'] = [
            'href' => $this->generateUrl('admin_orders_create'),
            'desc' => $this->trans('Add new order', [], 'Admin.Orderscustomers.Feature'),
            'icon' => 'add_circle_outline',
            'disabled' => !$isSingleShopContext,
        ];

        if (!$isSingleShopContext) {
            $toolbarButtons['add']['help'] = $this->trans(
                'You can use this feature in a single-store context only. Switch contexts to enable it.',
                [],
                'Admin.Orderscustomers.Feature'
            );
            $toolbarButtons['add']['href'] = '#';
        }

        return $toolbarButtons;
    }

    /**
     * Places an order from BO
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))")]
    public function placeAction(
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.handler.cart_summary_form_handler')] FormHandlerInterface $formHandler
    ) {
        $summaryForm = $this->createForm(CartSummaryType::class);
        $summaryForm->handleRequest($request);

        try {
            $result = $formHandler->handle($summaryForm);
            if ($result->getIdentifiableObjectId() instanceof OrderId) {
                /** @var OrderId $orderId */
                $orderId = $result->getIdentifiableObjectId();

                return $this->redirectToRoute('admin_orders_view', [
                    'orderId' => $orderId->getValue(),
                ]);
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_create');
    }

    /**
     * Renders create order page.
     * Whole page dynamics are on javascript side.
     * To load specific cart pass cartId to url query params (handled by javascript)
     *
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))")]
    public function createAction(
        Request $request,
        LanguageByIdChoiceProvider $languageChoiceProvider,
        #[Autowire(service: 'prestashop.core.form.choice_provider.currency_by_id')] FormChoiceProviderInterface $currencyChoiceProvider,
    ) {
        $isSingleShopContext = $this->getShopContext()->getShopConstraint()->isSingleShopContext();
        if (!$isSingleShopContext) {
            $this->addFlash('error', $this->trans(
                'You have to select a shop before creating new orders.',
                [],
                'Admin.Orderscustomers.Notification'
            ));

            return $this->redirectToRoute('admin_orders_index');
        }

        $summaryForm = $this->createForm(CartSummaryType::class);
        $languages = $languageChoiceProvider->getChoices(
            [
                'shop_id' => $this->getShopContext()->getShopConstraint()->getShopId()?->getValue(),
            ]
        );

        $configuration = $this->getConfiguration();

        return $this->render('@PrestaShop/Admin/Sell/Order/Order/create.html.twig', [
            'currencies' => $currencyChoiceProvider->getChoices(),
            'languages' => $languages,
            'summaryForm' => $summaryForm->createView(),
            'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
            'enableSidebar' => true,
            'recycledPackagingEnabled' => (bool) $configuration->get('PS_RECYCLABLE_PACK'),
            'giftSettingsEnabled' => (bool) $configuration->get('PS_GIFT_WRAPPING'),
            'stockManagementEnabled' => (bool) $configuration->get('PS_STOCK_MANAGEMENT'),
            'isB2BEnabled' => (bool) $configuration->get('PS_B2B_ENABLE'),
            'layoutTitle' => $this->trans('New order', [], 'Admin.Navigation.Menu'),
        ]);
    }

    /**
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function searchAction(
        Request $request,
        #[Autowire(service: 'prestashop.core.grid.definition.factory.order')] OrderGridDefinitionFactory $orderGridDefinitionFactory,
    ) {
        return $this->buildSearchResponse(
            $orderGridDefinitionFactory,
            $request,
            OrderGridDefinitionFactory::GRID_ID,
            'admin_orders_index'
        );
    }

    /**
     * Generates invoice PDF for given order
     *
     * @param int $orderId
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function generateInvoicePdfAction(
        int $orderId,
        #[Autowire(service: 'prestashop.adapter.pdf.order_invoice_pdf_generator')] OrderInvoicePdfGenerator $invoicePdfGenerator,
    ): BinaryFileResponse {
        return new BinaryFileResponse($invoicePdfGenerator->generatePDF([$orderId]));
    }

    /**
     * Generates delivery slip PDF for given order
     *
     * @param int $orderId
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function generateDeliverySlipPdfAction(
        int $orderId,
        #[Autowire(service: 'prestashop.adapter.pdf.delivery_slip_pdf_generator')] PDFGeneratorInterface $deliverySlipPdfGenerator,
    ): BinaryFileResponse {
        return new BinaryFileResponse($deliverySlipPdfGenerator->generatePDF([$orderId]));
    }

    /**
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function changeOrdersStatusAction(Request $request)
    {
        $changeOrdersStatusForm = $this->createForm(ChangeOrdersStatusType::class);
        $changeOrdersStatusForm->handleRequest($request);

        $data = $changeOrdersStatusForm->getData();

        try {
            $this->dispatchCommand(
                new BulkChangeOrderStatusCommand($data['order_ids'], (int) $data['new_order_status_id'])
            );

            $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));
        } catch (ChangeOrderStatusException $e) {
            $this->handleChangeOrderStatusException($e);
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_index');
    }

    /**
     * @param OrderFilters $filters
     *
     * @return CsvResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function exportAction(
        OrderFilters $filters,
        #[Autowire(service: 'prestashop.core.grid.factory.order')] GridFactory $orderGridFactory,
    ) {
        $isB2bEnabled = $this->getConfiguration()->get('PS_B2B_ENABLE');

        $filters = new OrderFilters(['limit' => null] + $filters->all());
        $orderGrid = $orderGridFactory->getGrid($filters);

        $headers = [
            'id_order' => $this->trans('ID', [], 'Admin.Global'),
            'reference' => $this->trans('Reference', [], 'Admin.Global'),
            'new' => $this->trans('New client', [], 'Admin.Orderscustomers.Feature'),
            'country_name' => $this->trans('Delivery', [], 'Admin.Global'),
            'customer' => $this->trans('Customer', [], 'Admin.Global'),
            'total_paid_tax_incl' => $this->trans('Total', [], 'Admin.Global'),
            'payment' => $this->trans('Payment', [], 'Admin.Global'),
            'osname' => $this->trans('Status', [], 'Admin.Global'),
            'date_add' => $this->trans('Date', [], 'Admin.Global'),
        ];

        if ($isB2bEnabled) {
            $headers['company'] = $this->trans('Company', [], 'Admin.Global');
        }

        $data = [];

        foreach ($orderGrid->getData()->getRecords()->all() as $record) {
            $item = [
                'id_order' => $record['id_order'],
                'reference' => $record['reference'],
                'new' => $record['new'],
                'country_name' => $record['country_name'],
                'customer' => $record['customer'],
                'total_paid_tax_incl' => $record['total_paid_tax_incl'],
                'payment' => $record['payment'],
                'osname' => $record['osname'],
                'date_add' => $record['date_add'],
            ];

            if ($isB2bEnabled) {
                $item['company'] = $record['company'];
            }

            $data[] = $item;
        }

        return (new CsvResponse())
            ->setData($data)
            ->setHeadersData($headers)
            ->setFileName('order_' . date('Y-m-d_His') . '.csv');
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function viewAction(
        int $orderId,
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.cancel_product_form_builder')] FormBuilderInterface $formBuilder,
        #[Autowire(service: 'prestashop.adapter.order.order_sibling_provider')] OrderSiblingProviderInterface $orderSiblingProvider,
        CurrencyDataProvider $currencyDataProvider,
        FeatureFlagStateCheckerInterface $featureFlagStateChecker,
        #[Autowire(service: 'PrestaShop\PrestaShop\Core\Grid\Factory\ShipmentFactory')] GridFactoryInterface $shipmentGridFactory,
        ShipmentFilters $filters,
        Tools $tools,
    ): Response {
        try {
            /** @var OrderForViewing $orderForViewing */
            $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId, QuerySorting::DESC));
        } catch (OrderException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));

            return $this->redirectToRoute('admin_orders_index');
        }

        $filters = new ShipmentFilters(['filters' => ['order_id' => $orderId]] + $filters->all());
        $shipmentsGrid = $shipmentGridFactory->getGrid($filters);

        $updateOrderStatusForm = $this->formFactory->createNamed(
            'update_order_status',
            UpdateOrderStatusType::class, [
                'new_order_status_id' => $orderForViewing->getHistory()->getCurrentOrderStatusId(),
            ]
        );
        $updateOrderStatusActionBarForm = $this->formFactory->createNamed(
            'update_order_status_action_bar',
            UpdateOrderStatusType::class, [
                'new_order_status_id' => $orderForViewing->getHistory()->getCurrentOrderStatusId(),
            ]
        );

        $addOrderCartRuleForm = $this->createForm(AddOrderCartRuleType::class, [], [
            'order_id' => $orderId,
        ]);
        $addOrderPaymentForm = $this->createForm(OrderPaymentType::class, [
            'id_currency' => $orderForViewing->getCurrencyId(),
        ], [
            'id_order' => $orderId,
        ]);

        $orderMessageForm = $this->createForm(OrderMessageType::class, [
            'lang_id' => $orderForViewing->getCustomer()->getLanguageId(),
        ], [
            'action' => $this->generateUrl('admin_orders_send_message', ['orderId' => $orderId]),
        ]);
        $orderMessageForm->handleRequest($request);

        $changeOrderCurrencyForm = $this->createForm(ChangeOrderCurrencyType::class, [], [
            'current_currency_id' => $orderForViewing->getCurrencyId(),
        ]);

        $changeOrderAddressForm = null;
        $privateNoteForm = null;

        if (null !== $orderForViewing->getCustomer() && $orderForViewing->getCustomer()->getId() !== 0) {
            $changeOrderAddressForm = $this->createForm(ChangeOrderAddressType::class, [], [
                'customer_id' => $orderForViewing->getCustomer()->getId(),
            ]);

            $privateNoteForm = $this->createForm(PrivateNoteType::class, [
                'note' => $orderForViewing->getCustomer()->getPrivateNote(),
            ]);
        }

        $updateOrderShippingForm = $this->createForm(UpdateOrderShippingType::class, [
            'new_carrier_id' => $orderForViewing->getCarrierId(),
        ], [
            'order_id' => $orderId,
        ]);

        // @todo: Fix me. Should not rely on legacy object model - Currency
        $orderCurrency = $currencyDataProvider->getCurrencyById($orderForViewing->getCurrencyId());

        $addProductRowForm = $this->createForm(AddProductRowType::class, [], [
            'order_id' => $orderId,
            'currency_id' => $orderForViewing->getCurrencyId(),
            'symbol' => $orderCurrency->symbol,
        ]);
        $editProductRowForm = $this->createForm(EditProductRowType::class, [], [
            'order_id' => $orderId,
            'symbol' => $orderCurrency->symbol,
        ]);

        $internalNoteForm = $this->createForm(InternalNoteType::class, [
            'note' => $orderForViewing->getNote(),
        ]);

        $backOfficeOrderButtons = new ActionsBarButtonsCollection();

        try {
            $this->dispatchHookWithParameters(
                'actionGetAdminOrderButtons',
                [
                    'controller' => $this,
                    'id_order' => $orderId,
                    'actions_bar_buttons_collection' => $backOfficeOrderButtons,
                ]
            );

            $cancelProductForm = $formBuilder->getFormFor($orderId);
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));

            return $this->redirectToRoute('admin_orders_index');
        }

        $this->handleOutOfStockProduct($orderForViewing);

        $merchandiseReturnEnabled = (bool) $this->getConfiguration()->get('PS_ORDER_RETURN');

        $paginationNum = ($this->getConfiguration()->get('PS_ORDER_PRODUCTS_NB_PER_PAGE') ?? self::DEFAULT_PRODUCTS_NUMBER);
        $paginationNumOptions = self::PRODUCTS_PAGINATION_OPTIONS;
        if (!in_array($paginationNum, $paginationNumOptions)) {
            $paginationNumOptions[] = $paginationNum;
        }
        sort($paginationNumOptions);

        $metatitle = sprintf(
            '%s %s %s',
            $this->trans('Orders', [], 'Admin.Orderscustomers.Feature'),
            $this->getConfiguration()->get('PS_NAVIGATION_PIPE') ?? '>',
            $this->trans(
                'Order %reference% from %firstname% %lastname%',
                [
                    '%reference%' => $orderForViewing->getReference(),
                    '%firstname%' => $orderForViewing->getCustomer()->getFirstName(),
                    '%lastname%' => $orderForViewing->getCustomer()->getLastName(),
                ],
                'Admin.Orderscustomers.Feature',
            )
        );

        $shipmentsLabel = $this->trans(
            'Shipments ([1]%shipment_count%[/1])',
            [
                '%shipment_count%' => $shipmentsGrid->getData()->getRecordsTotal(),
                '[1]' => '<span class="count">',
                '[/1]' => '</span>',
            ],
            'Admin.Shipping.Feature'
        );

        $carriersLabel = $this->trans(
            'Carriers ([1]%carriers_count%[/1])',
            [
                '%carriers_count%' => count($orderForViewing->getShipping()->getCarriers()),
                '[1]' => '<span class="count">',
                '[/1]' => '</span>',
            ],
            'Admin.Shipping.Feature'
        );

        return $this->render('@PrestaShop/Admin/Sell/Order/Order/view.html.twig', [
            'showContentHeader' => true,
            'enableSidebar' => true,
            'orderCurrency' => $orderCurrency,
            'meta_title' => $metatitle,
            'help_link' => $this->generateSidebarLink($request->attributes->get('_legacy_controller')),
            'orderForViewing' => $orderForViewing,
            'addOrderCartRuleForm' => $addOrderCartRuleForm->createView(),
            'updateOrderStatusForm' => $updateOrderStatusForm->createView(),
            'updateOrderStatusActionBarForm' => $updateOrderStatusActionBarForm->createView(),
            'addOrderPaymentForm' => $addOrderPaymentForm->createView(),
            'changeOrderCurrencyForm' => $changeOrderCurrencyForm->createView(),
            'privateNoteForm' => $privateNoteForm?->createView(),
            'updateOrderShippingForm' => $updateOrderShippingForm->createView(),
            'cancelProductForm' => $cancelProductForm->createView(),
            'invoiceManagementIsEnabled' => $orderForViewing->isInvoiceManagementIsEnabled(),
            'changeOrderAddressForm' => $changeOrderAddressForm?->createView(),
            'orderMessageForm' => $orderMessageForm->createView(),
            'addProductRowForm' => $addProductRowForm->createView(),
            'editProductRowForm' => $editProductRowForm->createView(),
            'backOfficeOrderButtons' => $backOfficeOrderButtons,
            'merchandiseReturnEnabled' => $merchandiseReturnEnabled,
            'priceSpecification' => $this->getLanguageContext()->getPriceSpecification($orderCurrency->iso_code)->toArray(),
            'previousOrderId' => $orderSiblingProvider->getPreviousOrderId($orderId),
            'nextOrderId' => $orderSiblingProvider->getNextOrderId($orderId),
            'paginationNum' => $paginationNum,
            'paginationNumOptions' => $paginationNumOptions,
            'isAvailableQuantityDisplayed' => (bool) $this->getConfiguration()->get('PS_STOCK_MANAGEMENT'),
            'internalNoteForm' => $internalNoteForm->createView(),
            'isImprovedShipmentFeatureFlagEnabled' => $featureFlagStateChecker->isEnabled(FeatureFlagSettings::FEATURE_FLAG_IMPROVED_SHIPMENT),
            'orderHasShipment' => $this->orderHasShipment($orderForViewing->getId()),
            'shipmentsGrid' => $this->presentGrid($shipmentsGrid),
            'shipmentsLabel' => $tools->purifyHTML($shipmentsLabel),
            'carriersLabel' => $tools->purifyHTML($carriersLabel),
        ]);
    }

    #[AdminSecurity("is_granted('update', 'AdminOrders')", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function getMergeShipmentForm(int $orderId, Request $request): Response
    {
        $shipmentId = (int) $request->query->get('shipmentId');
        $formData = $this->getMergeFormData($orderId, $shipmentId);

        $form = $this->createForm(MergeShipmentType::class, null, $formData);

        return $this->render('@PrestaShop/Admin/Sell/Order/Order/Blocks/View/merge_shipment_form.html.twig', [
            'mergeShipmentForm' => $form->createView(),
            'orderId' => $orderId,
            'shipmentId' => $shipmentId,
            'products' => $formData['products'],
        ]);
    }

    #[AdminSecurity("is_granted('update', 'AdminOrders')", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function getEditShipmentForm(int $orderId, Request $request): Response
    {
        $shipmentId = (int) $request->query->get('shipmentId');
        $formData = $this->dispatchQuery(new GetShipmentForEditing($orderId, $shipmentId))->toArray();
        $formData['shipment_id'] = $shipmentId;
        $form = $this->createForm(EditShipmentType::class, $formData, ['order_id' => $orderId, 'shipment_id' => $shipmentId]);

        return $this->render('@PrestaShop/Admin/Sell/Order/Order/Blocks/View/edit_shipment_form.html.twig', [
            'editShipmentForm' => $form->createView(),
            'shipmentInformation' => $form->getData(),
            'orderId' => $orderId,
            'shipmentId' => $shipmentId,
        ]);
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', 'AdminOrders')", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function mergeShipmentAction(int $orderId, Request $request): RedirectResponse
    {
        $shipmentId = (int) $request->query->get('shipmentId');
        $formData = $this->getMergeFormData($orderId, $shipmentId);

        $form = $this->createForm(MergeShipmentType::class, null, $formData);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Invalid merge shipment form.');

            return $this->redirectToRoute('admin_orders_view', ['orderId' => $orderId]);
        }

        /** @var OrderShipment $targetShipment */
        $targetShipment = $form->get('merge_to_shipment')->getData();
        $targetShipmentId = $targetShipment->getId();

        $submittedData = $request->request->all('merge_shipment');
        $selectedProducts = [];

        foreach ($submittedData as $key => $value) {
            if (str_starts_with($key, 'product_') && $value) {
                $orderDetailId = (int) str_replace('product_', '', $key);
                $quantityKey = 'quantity_' . $orderDetailId;

                $selectedProducts[] = [
                    'id_order_detail' => $orderDetailId,
                    'quantity' => (int) $submittedData[$quantityKey],
                ];
            }
        }

        $command = new MergeProductsToShipment(
            $shipmentId,
            $targetShipmentId,
            $selectedProducts
        );
        $this->dispatchCommand($command);

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', 'AdminOrders')", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function editShipmentAction(int $orderId, Request $request): RedirectResponse
    {
        $shipmentId = (int) $request->query->get('shipmentId');
        $formData = $this->dispatchQuery(new GetShipmentForEditing($orderId, $shipmentId))->toArray();
        $formData['shipment_id'] = $shipmentId;
        $form = $this->createForm(EditShipmentType::class, $formData, ['order_id' => $orderId, 'shipment_id' => $shipmentId]);
        $form->handleRequest($request);
        $submittedData = $request->request->all('edit_shipment');

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'An error occurend while editing shipment');

            return $this->redirectToRoute('admin_orders_view', ['orderId' => $orderId]);
        }

        $command = new EditShipment(
            $shipmentId,
            $submittedData['tracking_number'],
            $submittedData['carrier']
        );

        $this->dispatchCommand($command);

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    private function getMergeFormData(int $orderId, int $shipmentId): array
    {
        /** @var OrderShipmentProduct[] $products */
        $products = $this->dispatchQuery(new GetShipmentProducts($shipmentId));

        /** @var OrderShipment[] $shipments */
        $shipments = $this->dispatchQuery(new GetOrderShipments($orderId));

        $shipments = array_filter($shipments, fn (OrderShipment $s) => $s->getId() !== $shipmentId);

        foreach ($products as &$p) {
            $p = $p->toArray();
        }

        return [
            'products' => $products,
            'shipments' => $shipments,
        ];
    }

    #[AdminSecurity("is_granted('update', 'AdminOrders')", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function splitShipmentAction(int $orderId, int $shipmentId, Request $request): RedirectResponse
    {
        $data = $request->get('split_shipment', []);
        $carrier = isset($data['carrier']) ? (int) $data['carrier'] : null;

        $productsRaw = $data['products'] ?? [];
        $products = [];

        foreach ($productsRaw as $prod) {
            if (isset($prod['selected']) && (int) $prod['selected'] === 1) {
                $products[] = [
                    'id_order_detail' => (int) $prod['order_detail_id'],
                    'quantity' => (int) $prod['selected_quantity'],
                ];
            }
        }

        $this->dispatchQuery(new SplitShipment($shipmentId, $products, $carrier));

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
            'shipmentId' => $shipmentId,
        ]);
    }

    /**
     * @throws CoreException
     * @throws Exception
     */
    #[AdminSecurity("is_granted('update', 'AdminOrders')", message: 'You do not have permission to show this.')]
    public function getSplitShipmentForm(
        int $orderId,
        Request $request,
        #[Autowire(service: 'PrestaShop\PrestaShop\Adapter\Order\Repository\OrderDetailRepository')] OrderDetailRepository $orderDetailRepository,
    ): Response {
        $shipmentId = $request->query->getInt('shipmentId');
        $productsFromQuery = $request->get('products', []);
        $selectedCarrier = $request->query->getInt('carrier');
        $orderShipmentProducts = $this->mergeProductsFromQueries($shipmentId, $productsFromQuery, $orderDetailRepository);

        $formIsValid = $this->checkFormValidity($orderShipmentProducts);

        $splitShipmentTypeForm = $this->createForm(SplitShipmentType::class, [
            'products' => $orderShipmentProducts,
            'carrier' => $selectedCarrier,
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
        ]);

        return $this->render('@PrestaShop/Admin/Sell/Order/Order/Blocks/View/split_shipment_form.html.twig', [
            'splitShipmentForm' => $splitShipmentTypeForm->createView(),
            'orderId' => $orderId,
            'shipmentId' => $shipmentId,
            'formIsValid' => $formIsValid,
        ]);
    }

    /**
     * @param array<array{
     *      selected?: bool,
     *      selected_quantity?: int,
     *      order_detail_id: int,
     *      quantity: int,
     *      product_name: string,
     *      product_reference: string,
     *      product_image_path: string
     *  }> $products
     *
     * @return bool
     */
    private function checkFormValidity(array $products): bool
    {
        $allSelected = array_reduce($products, fn ($carry, $product) => $carry && ($product['selected'] ?? false), true);
        $allQuantitiesMatch = array_reduce($products, fn ($carry, $product) => $carry && (($product['selected_quantity'] ?? 0) === $product['quantity']), true);

        return !($allSelected && $allQuantitiesMatch);
    }

    /**
     * @param int $shipmentId
     * @param array<array{selected: string, selected_quantity: string, order_detail_id: string}> $productsFromQuery
     * @param OrderDetailRepository $orderDetailRepository
     *
     * @return array<array{
     *     selected?: bool,
     *     selected_quantity?: int,
     *     order_detail_id: int,
     *     quantity: int,
     *     product_name: string,
     *     product_reference: string,
     *     product_image_path: string
     * }>
     *
     * @throws CoreException
     * @throws ShipmentException
     */
    private function mergeProductsFromQueries(
        int $shipmentId,
        array $productsFromQuery,
        OrderDetailRepository $orderDetailRepository,
    ): array {
        foreach ($productsFromQuery as &$product) {
            if (isset($product['selected_quantity'])) {
                $product['selected_quantity'] = (int) $product['selected_quantity'];
            }
            if (isset($product['selected'])) {
                $product['selected'] = (bool) filter_var((int) $product['selected'], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $productsQueryMap = array_column(
            $productsFromQuery,
            null,
            'order_detail_id'
        );

        /** @var OrderShipmentProduct[] $orderShipmentProducts */
        $orderShipmentProducts = $this->dispatchQuery(new GetShipmentProducts($shipmentId));

        $mergedProducts = [];

        foreach ($orderShipmentProducts as $product) {
            $productArray = $product->toArray();
            $id = $productArray['order_detail_id'] ?? null;
            if ($id !== null && isset($productsQueryMap[$id])) {
                $productArray['product_id'] = $orderDetailRepository
                    ->get(new OrderDetailId($productArray['order_detail_id']))
                    ->product_id;
                $productArray = array_merge($productsQueryMap[$id], $productArray);
            }

            $mergedProducts[] = $productArray;
        }

        return $mergedProducts;
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller')) && is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function partialRefundAction(
        int $orderId,
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.cancel_product_form_builder')] FormBuilderInterface $formBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.partial_refund_form_handler')] FormHandlerInterface $formHandler,
    ) {
        $form = $formBuilder->getFormFor($orderId);

        try {
            $form->handleRequest($request);
            $result = $formHandler->handleFor($orderId, $form);
            if ($result->isSubmitted()) {
                if ($result->isValid()) {
                    $this->addFlash('success', $this->trans('A partial refund was successfully created.', [], 'Admin.Orderscustomers.Notification'));
                } else {
                    $this->addFlashFormErrors($form);
                }
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller')) && is_granted('delete', request.get('_legacy_controller'))")]
    public function standardRefundAction(
        int $orderId,
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.cancel_product_form_builder')] FormBuilderInterface $formBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.standard_refund_form_handler')] FormHandlerInterface $formHandler,
    ) {
        $form = $formBuilder->getFormFor($orderId);

        try {
            $form->handleRequest($request);
            $result = $formHandler->handleFor($orderId, $form);
            if ($result->isSubmitted()) {
                if ($result->isValid()) {
                    $this->addFlash('success', $this->trans('A standard refund was successfully created.', [], 'Admin.Orderscustomers.Notification'));
                } else {
                    $this->addFlashFormErrors($form);
                }
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller')) && is_granted('delete', request.get('_legacy_controller'))")]
    public function returnProductAction(
        int $orderId,
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.cancel_product_form_builder')] FormBuilderInterface $formBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.return_product_form_handler')] FormHandlerInterface $formHandler,
    ) {
        $form = $formBuilder->getFormFor($orderId);

        try {
            $form->handleRequest($request);
            $result = $formHandler->handleFor($orderId, $form);
            if ($result->isSubmitted()) {
                if ($result->isValid()) {
                    $this->addFlash('success', $this->trans('The product was successfully returned.', [], 'Admin.Orderscustomers.Notification'));
                } else {
                    $this->addFlashFormErrors($form);
                }
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param OrderForViewing $orderForViewing
     */
    private function handleOutOfStockProduct(OrderForViewing $orderForViewing)
    {
        $isStockManagementEnabled = (bool) $this->getConfiguration()->get('PS_STOCK_MANAGEMENT');
        if (!$isStockManagementEnabled || $orderForViewing->isDelivered() || $orderForViewing->isShipped()) {
            return;
        }

        foreach ($orderForViewing->getProducts()->getProducts() as $product) {
            if ($product->getAvailableQuantity() <= 0) {
                $this->addFlash(
                    'warning',
                    $this->trans('This product is out of stock:', [], 'Admin.Orderscustomers.Notification') . ' ' . $product->getName()
                );
            }
        }
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function addProductAction(
        int $orderId,
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.cancel_product_form_builder')] FormBuilderInterface $formBuilder,
        CurrencyDataProvider $currencyDataProvider
    ): Response {
        /** @var OrderForViewing $orderForViewing */
        $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId, QuerySorting::DESC));

        $previousProducts = [];
        foreach ($orderForViewing->getProducts()->getProducts() as $orderProductForViewing) {
            $previousProducts[$orderProductForViewing->getOrderDetailId()] = $orderProductForViewing;
        }

        $invoiceId = (int) $request->get('invoice_id');
        try {
            if ($invoiceId > 0) {
                $addProductCommand = AddProductToOrderCommand::toExistingInvoice(
                    $orderId,
                    $invoiceId,
                    (int) $request->get('product_id'),
                    (int) $request->get('combination_id'),
                    $request->get('price_tax_incl'),
                    $request->get('price_tax_excl'),
                    (int) $request->get('quantity')
                );
            } else {
                $hasFreeShipping = null;
                if ($request->request->has('free_shipping')) {
                    $hasFreeShipping = (bool) filter_var($request->get('free_shipping'), FILTER_VALIDATE_BOOLEAN);
                }
                $addProductCommand = AddProductToOrderCommand::withNewInvoice(
                    $orderId,
                    (int) $request->get('product_id'),
                    (int) $request->get('combination_id'),
                    $request->get('price_tax_incl'),
                    $request->get('price_tax_excl'),
                    (int) $request->get('quantity'),
                    $hasFreeShipping
                );
            }
            $this->dispatchCommand($addProductCommand);
        } catch (Exception $e) {
            return $this->json(
                ['message' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_BAD_REQUEST
            );
        }

        /**
         * Returning the products list view is not required since we reload the whole list
         * We keep it for now to avoid Breaking Change
         */
        /** @var OrderForViewing $orderForViewing */
        $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId, QuerySorting::DESC));

        $updatedProducts = [];
        foreach ($orderForViewing->getProducts()->getProducts() as $orderProductForViewing) {
            $updatedProducts[$orderProductForViewing->getOrderDetailId()] = $orderProductForViewing;
        }

        $newProducts = array_diff_key($updatedProducts, $previousProducts);

        $cancelProductForm = $formBuilder->getFormFor($orderId);

        $orderCurrency = $currencyDataProvider->getCurrencyById($orderForViewing->getCurrencyId());

        $addedGridRows = '';
        foreach ($newProducts as $newProduct) {
            $addedGridRows .= $this->renderView('@PrestaShop/Admin/Sell/Order/Order/Blocks/View/product.html.twig', [
                'orderForViewing' => $orderForViewing,
                'product' => $newProduct,
                'isColumnLocationDisplayed' => $newProduct->getLocation() !== '',
                'isColumnRefundedDisplayed' => $newProduct->getQuantityRefunded() > 0,
                'isAvailableQuantityDisplayed' => (bool) $this->getConfiguration()->get('PS_STOCK_MANAGEMENT'),
                'cancelProductForm' => $cancelProductForm->createView(),
                'orderCurrency' => $orderCurrency,
            ]);
        }

        return new Response($addedGridRows);
    }

    /**
     * @param int $orderId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function getProductPricesAction(int $orderId): Response
    {
        try {
            $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId));
            $productsForViewing = $orderForViewing->getProducts();
            $productList = $productsForViewing->getProducts();

            $result = [];
            foreach ($productList as $product) {
                $result[] = [
                    'orderDetailId' => $product->getOrderDetailId(),
                    'unitPrice' => $product->getUnitPrice(),
                    'unitPriceTaxExclRaw' => $product->getUnitPriceTaxExclRaw(),
                    'unitPriceTaxInclRaw' => $product->getUnitPriceTaxInclRaw(),
                    'quantity' => $product->getQuantity(),
                    'availableQuantity' => $product->getAvailableQuantity(),
                    'totalPrice' => $product->getTotalPrice(),
                ];
            }
        } catch (Exception $e) {
            return $this->json(
                ['message' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $this->json($result);
    }

    /**
     * @param int $orderId
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function getInvoicesAction(
        int $orderId,
        #[Autowire(service: 'prestashop.adapter.form.choice_provider.order_invoice_by_id')] ConfigurableFormChoiceProviderInterface $choiceProvider,
    ) {
        $choices = $choiceProvider->getChoices([
            'id_order' => $orderId,
            'id_lang' => $this->getLanguageContext()->getId(),
            'display_total' => true,
        ]);

        return $this->json([
            'invoices' => $choices,
        ]);
    }

    /**
     * @param int $orderId
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function getDocumentsAction(int $orderId)
    {
        /** @var OrderForViewing $orderForViewing */
        $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId));

        return $this->json([
            'total' => count($orderForViewing->getDocuments()->getDocuments()),
            'html' => $this->render('@PrestaShop/Admin/Sell/Order/Order/Blocks/View/documents.html.twig', [
                'orderForViewing' => $orderForViewing,
            ])->getContent(),
        ]);
    }

    /**
     * @param int $orderId
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function getShippingAction(int $orderId)
    {
        /** @var OrderForViewing $orderForViewing */
        $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId));

        return $this->json([
            'total' => count($orderForViewing->getShipping()->getCarriers()),
            'html' => $this->render('@PrestaShop/Admin/Sell/Order/Order/Blocks/View/shipping.html.twig', [
                'orderForViewing' => $orderForViewing,
            ])->getContent(),
        ]);
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function updateShippingAction(int $orderId, Request $request): RedirectResponse
    {
        $form = $this->createForm(UpdateOrderShippingType::class, [], [
            'order_id' => $orderId,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            try {
                $this->dispatchCommand(
                    new UpdateOrderShippingDetailsCommand(
                        $orderId,
                        (int) $data['current_order_carrier_id'],
                        (int) $data['new_carrier_id'],
                        $data['tracking_number']
                    )
                );

                $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));
            } catch (TransistEmailSendingException) {
                $this->addFlash(
                    'error',
                    $this->trans(
                        'An error occurred while sending an email to the customer.',
                        [],
                        'Admin.Orderscustomers.Notification'
                    )
                );
            } catch (Exception $e) {
                $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
            }
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     * @param int $orderCartRuleId
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', 'AdminOrders')", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function removeCartRuleAction(int $orderId, int $orderCartRuleId): RedirectResponse
    {
        $this->dispatchCommand(
            new DeleteCartRuleFromOrderCommand($orderId, $orderCartRuleId)
        );

        $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     * @param int $orderInvoiceId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', 'AdminOrders')", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function updateInvoiceNoteAction(int $orderId, int $orderInvoiceId, Request $request): RedirectResponse
    {
        try {
            $this->dispatchCommand(new UpdateInvoiceNoteCommand(
                $orderInvoiceId,
                $request->request->get('invoice_note')
            ));
            $this->addFlash('success', $this->trans('Update successful', [], 'Admin.Notifications.Success'));
        } catch (InvoiceException $e) {
            $this->addFlash(
                'error',
                $this->getErrorMessageForException($e, $this->getErrorMessages($e))
            );
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     * @param int $orderDetailId
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function updateProductAction(
        int $orderId,
        int $orderDetailId,
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.cancel_product_form_builder')] FormBuilderInterface $formBuilder,
        CurrencyDataProvider $currencyDataProvider
    ): Response {
        try {
            $this->dispatchCommand(
                new UpdateProductInOrderCommand(
                    $orderId,
                    $orderDetailId,
                    $request->get('price_tax_incl'),
                    $request->get('price_tax_excl'),
                    (int) $request->get('quantity'),
                    (int) $request->get('invoice')
                )
            );
        } catch (Exception $e) {
            return $this->json(
                ['message' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_BAD_REQUEST
            );
        }

        /** @var OrderForViewing $orderForViewing */
        $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId, QuerySorting::DESC));

        $products = $orderForViewing->getProducts()->getProducts();
        $product = array_reduce($products, function ($result, OrderProductForViewing $item) use ($orderDetailId) {
            return $item->getOrderDetailId() == $orderDetailId ? $item : $result;
        });

        // The whole product row has been removed so we return an empty response
        if (null === $product) {
            return new Response('');
        }

        $cancelProductForm = $formBuilder->getFormFor($orderId);

        $orderCurrency = $currencyDataProvider->getCurrencyById($orderForViewing->getCurrencyId());

        return $this->render('@PrestaShop/Admin/Sell/Order/Order/Blocks/View/product.html.twig', [
            'cancelProductForm' => $cancelProductForm->createView(),
            'isColumnLocationDisplayed' => $product->getLocation() !== '',
            'isColumnRefundedDisplayed' => $product->getQuantityRefunded() > 0,
            'isAvailableQuantityDisplayed' => (bool) $this->getConfiguration()->get('PS_STOCK_MANAGEMENT'),
            'orderCurrency' => $orderCurrency,
            'orderForViewing' => $orderForViewing,
            'product' => $product,
        ]);
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', 'AdminOrders')", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function addCartRuleAction(int $orderId, Request $request): RedirectResponse
    {
        $addOrderCartRuleForm = $this->createForm(AddOrderCartRuleType::class, [], [
            'order_id' => $orderId,
        ]);
        $addOrderCartRuleForm->handleRequest($request);

        if ($addOrderCartRuleForm->isSubmitted()) {
            if ($addOrderCartRuleForm->isValid()) {
                $data = $addOrderCartRuleForm->getData();

                try {
                    $this->dispatchCommand(
                        new AddCartRuleToOrderCommand(
                            $orderId,
                            $data['name'],
                            $data['type'],
                            $data['value'] ?? null,
                            empty($data['invoice_id']) ? null : (int) $data['invoice_id']
                        )
                    );

                    $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));
                } catch (Exception $e) {
                    $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
                }
            } else {
                foreach ($addOrderCartRuleForm->getErrors(true) as $error) {
                    $this->addFlash('error', $error->getMessage());
                }
            }
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function updateStatusAction(int $orderId, Request $request): RedirectResponse
    {
        $form = $this->formFactory->createNamed(
            'update_order_status',
            UpdateOrderStatusType::class
        );
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // Check if the form is submit from the action bar
            $form = $this->formFactory->createNamed(
                'update_order_status_action_bar',
                UpdateOrderStatusType::class
            );
            $form->handleRequest($request);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleOrderStatusUpdate($orderId, (int) $form->getData()['new_order_status_id']);
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * Updates order status directly from list page.
     *
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function updateStatusFromListAction(int $orderId, Request $request): RedirectResponse
    {
        $this->handleOrderStatusUpdate($orderId, $request->request->getInt('value'));

        return $this->redirectToRoute('admin_orders_index');
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', 'AdminOrders')", message: 'You do not have permission to edit this.', redirectQueryParamsToKeep: ['orderId'], redirectRoute: 'admin_orders_view')]
    public function addPaymentAction(
        int $orderId,
        Request $request,
    ): RedirectResponse {
        $form = $this->createForm(OrderPaymentType::class, [], [
            'id_order' => $orderId,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $data = $form->getData();

                try {
                    $this->dispatchCommand(
                        new AddPaymentCommand(
                            $orderId,
                            $data['date'],
                            $data['payment_method'],
                            $data['amount'],
                            $data['id_currency'],
                            $this->getEmployeeContext()->getEmployee()->getId(),
                            $data['id_invoice'],
                            $data['transaction_id']
                        )
                    );

                    $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));
                } catch (Exception $e) {
                    $this->addFlash('error', $this->getErrorMessageForException($e, $this->getPaymentErrorMessages($e)));
                }
            } else {
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('error', $error->getMessage());
                }
            }
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function previewAction(int $orderId): JsonResponse
    {
        try {
            /** @var OrderPreview $orderPreview */
            $orderPreview = $this->dispatchQuery(new GetOrderPreview($orderId));

            return $this->json([
                'preview' => $this->renderView('@PrestaShop/Admin/Sell/Order/Order/preview.html.twig', [
                    'orderPreview' => $orderPreview,
                    'productsPreviewLimit' => OrderConstraints::PRODUCTS_PREVIEW_LIMIT,
                    'orderId' => $orderId,
                ]),
            ]);
        } catch (Exception $e) {
            return $this->json(
                ['message' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_BAD_REQUEST
            );
        }
    }

    /**
     * Duplicates cart from specified order
     *
     * @param int $orderId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller')) || is_granted('create', 'AdminOrders')")]
    public function duplicateOrderCartAction(int $orderId)
    {
        $cartId = $this->dispatchCommand(new DuplicateOrderCartCommand($orderId))->getValue();

        return $this->json(
            $this->dispatchQuery(
                (new GetCartForOrderCreation($cartId))
                    ->setHideDiscounts(true)
            )
        );
    }

    /**
     * @param Request $request
     * @param int $orderId
     *
     * @return Response
     */
    #[DemoRestricted(redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'])]
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function sendMessageAction(
        Request $request,
        int $orderId,
        RouterInterface $router
    ): Response {
        $orderMessageForm = $this->createForm(OrderMessageType::class);

        $orderMessageForm->handleRequest($request);

        if ($orderMessageForm->isSubmitted() && $orderMessageForm->isValid()) {
            $data = $orderMessageForm->getData();

            try {
                $this->dispatchCommand(new AddOrderCustomerMessageCommand(
                    $orderId,
                    $data['message'],
                    !$data['is_displayed_to_customer']
                ));

                $this->addFlash(
                    'success',
                    $this->trans('Comment successfully added.', [], 'Admin.Notifications.Success')
                );
            } catch (CannotSendEmailException) {
                $this->addFlash(
                    'success',
                    $this->trans('Comment successfully added.', [], 'Admin.Notifications.Success')
                );

                $this->addFlash(
                    'error',
                    $this->trans(
                        'An error occurred while sending an email to the customer.',
                        [],
                        'Admin.Orderscustomers.Notification'
                    )
                );
            } catch (Exception $exception) {
                $this->addFlash(
                    'error',
                    $this->getErrorMessageForException($exception, $this->getCustomerMessageErrorMapping($exception))
                );
            }
        }

        $routesCollection = $router->getRouteCollection();

        if (!$orderMessageForm->isValid()
            && $viewRoute = $routesCollection->get('admin_orders_view')
        ) {
            $attributes = $viewRoute->getDefaults();
            $attributes['orderId'] = $orderId;

            return $this->forward(
                $viewRoute->getDefault('_controller'),
                $attributes
            );
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', 'AdminOrders')", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function changeCustomerAddressAction(Request $request): RedirectResponse
    {
        $orderId = $request->query->get('orderId');
        if (!$orderId) {
            return $this->redirectToRoute('admin_orders_index');
        }

        $customerId = $request->query->get('customerId');
        if (!$customerId) {
            return $this->redirectToRoute('admin_orders_index');
        }

        $changeOrderAddressForm = $this->createForm(ChangeOrderAddressType::class, [], [
            'customer_id' => (int) $request->query->get('customerId'),
        ]);
        $changeOrderAddressForm->handleRequest($request);

        if (!$changeOrderAddressForm->isSubmitted() || !$changeOrderAddressForm->isValid()) {
            return $this->redirectToRoute('admin_orders_view', [
                'orderId' => $orderId,
            ]);
        }

        $data = $changeOrderAddressForm->getData();

        try {
            if ($data['address_type'] === ChangeOrderAddressType::SHIPPING_TYPE) {
                $command = new ChangeOrderDeliveryAddressCommand((int) $orderId, (int) $data['new_address_id']);
            } else {
                $command = new ChangeOrderInvoiceAddressCommand((int) $orderId, (int) $data['new_address_id']);
            }

            $this->dispatchCommand($command);

            $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function changeCurrencyAction(int $orderId, Request $request): RedirectResponse
    {
        $changeOrderCurrencyForm = $this->createForm(ChangeOrderCurrencyType::class);
        $changeOrderCurrencyForm->handleRequest($request);

        if (!$changeOrderCurrencyForm->isSubmitted() || !$changeOrderCurrencyForm->isValid()) {
            return $this->redirectToRoute('admin_orders_view', [
                'orderId' => $orderId,
            ]);
        }

        $data = $changeOrderCurrencyForm->getData();

        try {
            $this->dispatchCommand(
                new ChangeOrderCurrencyCommand($orderId, (int) $data['new_currency_id'])
            );

            $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     * @param int $orderStatusId
     * @param int $orderHistoryId
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', 'AdminOrders')", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function resendEmailAction(int $orderId, int $orderStatusId, int $orderHistoryId): RedirectResponse
    {
        try {
            $this->dispatchCommand(
                new ResendOrderEmailCommand($orderId, $orderStatusId, $orderHistoryId)
            );

            $this->addFlash(
                'success',
                $this->trans('The message was successfully sent to the customer.', [], 'Admin.Orderscustomers.Notification')
            );
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     * @param int $orderDetailId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function deleteProductAction(int $orderId, int $orderDetailId): JsonResponse
    {
        try {
            $this->dispatchCommand(
                new DeleteProductFromOrderCommand($orderId, $orderDetailId)
            );

            return $this->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return $this->json(
                ['message' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_BAD_REQUEST
            );
        }
    }

    /**
     * @param int $orderId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function getDiscountsAction(int $orderId): Response
    {
        /** @var OrderForViewing $orderForViewing */
        $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId));

        return $this->render('@PrestaShop/Admin/Sell/Order/Order/Blocks/View/discount_list.html.twig', [
            'discounts' => $orderForViewing->getDiscounts()->getDiscounts(),
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param int $orderId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function getPricesAction(int $orderId): JsonResponse
    {
        /** @var OrderForViewing $orderForViewing */
        $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId));
        $orderForViewingPrices = $orderForViewing->getPrices();

        return $this->json([
            'orderTotalFormatted' => $orderForViewingPrices->getTotalAmountFormatted(),
            'discountsAmountFormatted' => $orderForViewingPrices->getDiscountsAmountFormatted(),
            'discountsAmountDisplayed' => $orderForViewingPrices->getDiscountsAmountRaw()->isGreaterThanZero(),
            'productsTotalFormatted' => $orderForViewingPrices->getProductsPriceFormatted(),
            'shippingTotalFormatted' => $orderForViewingPrices->getShippingPriceFormatted(),
            'shippingTotalDisplayed' => $orderForViewingPrices->getShippingPriceRaw()->isGreaterThanZero(),
            'taxesTotalFormatted' => $orderForViewingPrices->getTaxesAmountFormatted(),
        ]);
    }

    /**
     * @param int $orderId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function getPaymentsAction(int $orderId): Response
    {
        try {
            /** @var OrderForViewing $orderForViewing */
            $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId));

            return $this->render('@PrestaShop/Admin/Sell/Order/Order/Blocks/View/payments_alert.html.twig', [
                'payments' => $orderForViewing->getPayments(),
                'linkedOrders' => $orderForViewing->getLinkedOrders(),
            ]);
        } catch (Exception $e) {
            return $this->json(
                ['message' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_BAD_REQUEST
            );
        }
    }

    /**
     * @param int $orderId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function getProductsListAction(
        int $orderId,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.cancel_product_form_builder')] FormBuilderInterface $formBuilder,
        CurrencyDataProvider $currencyDataProvider
    ): Response {
        /** @var OrderForViewing $orderForViewing */
        $orderForViewing = $this->dispatchQuery(new GetOrderForViewing($orderId, QuerySorting::DESC));

        $orderCurrency = $currencyDataProvider->getCurrencyById($orderForViewing->getCurrencyId());

        $cancelProductForm = $formBuilder->getFormFor($orderId);

        $paginationNum = ($this->getConfiguration()->get('PS_ORDER_PRODUCTS_NB_PER_PAGE') ?? self::DEFAULT_PRODUCTS_NUMBER);
        $paginationNumOptions = self::PRODUCTS_PAGINATION_OPTIONS;
        if (!in_array($paginationNum, $paginationNumOptions)) {
            $paginationNumOptions[] = $paginationNum;
        }
        sort($paginationNumOptions);

        $isColumnLocationDisplayed = false;
        $isColumnRefundedDisplayed = false;

        foreach (array_slice($orderForViewing->getProducts()->getProducts(), $paginationNum) as $product) {
            if (!empty($product->getLocation())) {
                $isColumnLocationDisplayed = true;
            }
            if ($product->getQuantityRefunded() > 0) {
                $isColumnRefundedDisplayed = true;
            }
        }

        return $this->render('@PrestaShop/Admin/Sell/Order/Order/Blocks/View/product_list.html.twig', [
            'orderForViewing' => $orderForViewing,
            'cancelProductForm' => $cancelProductForm->createView(),
            'orderCurrency' => $orderCurrency,
            'paginationNum' => $paginationNum,
            'isColumnLocationDisplayed' => $isColumnLocationDisplayed,
            'isColumnRefundedDisplayed' => $isColumnRefundedDisplayed,
            'isAvailableQuantityDisplayed' => (bool) $this->getConfiguration()->get('PS_STOCK_MANAGEMENT'),
        ]);
    }

    /**
     * Generates invoice for given order
     *
     * @param int $orderId
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to generate this.')]
    public function generateInvoiceAction(int $orderId): RedirectResponse
    {
        try {
            $this->dispatchCommand(new GenerateInvoiceCommand($orderId));

            $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * Sends email with process order link to customer
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller')) || is_granted('create', 'AdminOrders')")]
    public function sendProcessOrderEmailAction(Request $request): JsonResponse
    {
        try {
            $this->dispatchCommand(new SendProcessOrderEmailCommand($request->request->getInt('cartId')));

            return $this->json([
                'message' => $this->trans('The email was sent to your customer.', [], 'Admin.Orderscustomers.Notification'),
            ]);
        } catch (Exception $e) {
            return $this->json(
                ['message' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @param int $orderId
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller')) && is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_view', redirectQueryParamsToKeep: ['orderId'], message: 'You do not have permission to edit this.')]
    public function cancellationAction(
        int $orderId,
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.cancel_product_form_builder')] FormBuilderInterface $formBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.cancellation_form_handler')] FormHandlerInterface $formHandler,
    ) {
        $form = $formBuilder->getFormFor($orderId);
        try {
            $form->handleRequest($request);
            $result = $formHandler->handleFor($orderId, $form);
            if ($result->isSubmitted()) {
                if ($result->isValid()) {
                    $this->addFlash('success', $this->trans('Selected products were successfully canceled.', [], 'Admin.Catalog.Notification'));
                } else {
                    $this->addFlashFormErrors($form);
                }
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function configureProductPaginationAction(Request $request): JsonResponse
    {
        $numPerPage = (int) $request->request->get('numPerPage');
        if ($numPerPage < 1) {
            $numPerPage = self::DEFAULT_PRODUCTS_NUMBER;
        }

        try {
            $this->getConfiguration()->set('PS_ORDER_PRODUCTS_NB_PER_PAGE', $numPerPage);
        } catch (Exception $e) {
            return $this->json(
                ['message' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Method for downloading customization picture
     *
     * @param int $orderId
     * @param string $value
     *
     * @return BinaryFileResponse|RedirectResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function displayCustomizationImageAction(
        int $orderId,
        string $value,
        LegacyContext $context
    ) {
        $uploadDir = $context->getUploadDirectory();
        $filePath = $uploadDir . $value;
        $filesystem = new Filesystem();

        try {
            if (!$filesystem->exists($filePath)) {
                $this->addFlash('error', $this->trans('The product customization picture could not be found.', [], 'Admin.Notifications.Error'));

                return $this->redirectToRoute('admin_orders_view', [
                    'orderId' => $orderId,
                ]);
            }

            $imageFile = new File($filePath);
            $fileName = sprintf('%s-customization-%s.%s', $orderId, $value, $imageFile->guessExtension() ?? 'jpg');

            return $this->file($filePath, $fileName);
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * Set order internal note.
     *
     * @param mixed $orderId
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller')) && is_granted('create', request.get('_legacy_controller'))", redirectRoute: 'admin_orders_index')]
    public function setInternalNoteAction($orderId, Request $request)
    {
        $internalNoteForm = $this->createForm(InternalNoteType::class);
        $internalNoteForm->handleRequest($request);

        if ($internalNoteForm->isSubmitted()) {
            $data = $internalNoteForm->getData();

            try {
                $this->dispatchCommand(new SetInternalOrderNoteCommand(
                    (int) $orderId,
                    $data['note']
                ));

                if ($request->isXmlHttpRequest()) {
                    return $this->json([
                        'success' => true,
                        'message' => $this->trans('Update successful', [], 'Admin.Notifications.Success'),
                    ]);
                }

                $this->addFlash('success', $this->trans('Update successful', [], 'Admin.Notifications.Success'));
            } catch (OrderException $e) {
                $this->addFlash(
                    'error',
                    $this->getErrorMessageForException($e, $this->getErrorMessages($e))
                );
            }
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => $orderId,
        ]);
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller')) && is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to perform this search.')]
    public function searchProductsAction(Request $request): JsonResponse
    {
        try {
            $defaultCurrencyId = (int) $this->getConfiguration()->get('PS_CURRENCY_DEFAULT');

            $searchPhrase = $request->query->get('search_phrase');
            $currencyId = $request->query->get('currency_id');
            $currencyIsoCode = $currencyId !== null
                ? Currency::getIsoCodeById((int) $currencyId)
                : Currency::getIsoCodeById($defaultCurrencyId);
            $orderId = null;
            if ($request->query->has('order_id')) {
                $orderId = (int) $request->query->get('order_id');
            }

            /** @var FoundProduct[] $foundProducts */
            $foundProducts = $this->dispatchQuery(new SearchProducts($searchPhrase, 10, $currencyIsoCode, $orderId));

            return $this->json([
                'products' => $foundProducts,
            ]);
        } catch (ProductSearchEmptyPhraseException $e) {
            return $this->json(
                ['message' => $this->getErrorMessageForException($e, $this->getErrorMessages($e))],
                Response::HTTP_BAD_REQUEST
            );
        } catch (Exception $e) {
            return $this->json(
                ['message' => $this->getErrorMessageForException($e, [])],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Initializes order status update
     *
     * @param int $orderId
     * @param int $orderStatusId
     */
    private function handleOrderStatusUpdate(int $orderId, int $orderStatusId): void
    {
        try {
            $this->dispatchCommand(
                new UpdateOrderStatusCommand(
                    $orderId,
                    $orderStatusId
                )
            );
            $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));
        } catch (ChangeOrderStatusException $e) {
            $this->handleChangeOrderStatusException($e);
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages($e)));
        }
    }

    /**
     * @param Exception $e
     *
     * @return array
     */
    private function getErrorMessages(Exception $e)
    {
        $refundableQuantity = 0;
        if ($e instanceof InvalidCancelProductException) {
            $refundableQuantity = $e->getRefundableQuantity();
        }
        $orderInvoiceNumber = '#unknown';
        if ($e instanceof DuplicateProductInOrderInvoiceException) {
            $orderInvoiceNumber = $e->getOrderInvoiceNumber();
        }

        return [
            ProductSearchEmptyPhraseException::class => $this->trans(
                'Product search phrase must not be an empty string.',
                [],
                'Admin.Orderscustomers.Notification'
            ),
            CannotEditDeliveredOrderProductException::class => $this->trans(
                'You cannot edit the cart once the order delivered.',
                [],
                'Admin.Orderscustomers.Notification'
            ),
            OrderNotFoundException::class => $e instanceof OrderNotFoundException ?
                $this->trans(
                    'Order #%d cannot be loaded.',
                    ['#%d' => $e->getOrderId()->getValue()],
                    'Admin.Orderscustomers.Notification',
                ) : '',
            OrderEmailSendException::class => $this->trans(
                'An error occurred while sending the e-mail to the customer.',
                [],
                'Admin.Orderscustomers.Notification'
            ),
            OrderException::class => $this->trans(
                $e->getMessage(),
                [],
                'Admin.Orderscustomers.Notification'
            ),
            InvoiceException::class => $this->trans(
                $e->getMessage(),
                [],
                'Admin.Orderscustomers.Notification'
            ),
            InvalidAmountException::class => $this->trans(
                'Only numbers and decimal points (".") are allowed in the amount fields, e.g. 10.50 or 1050.',
                [],
                'Admin.Orderscustomers.Notification'
            ),
            InvalidCartRuleDiscountValueException::class => [
                InvalidCartRuleDiscountValueException::INVALID_MIN_PERCENT => $this->trans(
                    'Percent value must be greater than 0.',
                    [],
                    'Admin.Orderscustomers.Notification'
                ),
                InvalidCartRuleDiscountValueException::INVALID_MAX_PERCENT => $this->trans(
                    'Percent value cannot exceed 100.',
                    [],
                    'Admin.Orderscustomers.Notification'
                ),
                InvalidCartRuleDiscountValueException::INVALID_MIN_AMOUNT => $this->trans(
                    'Amount value must be greater than 0.',
                    [],
                    'Admin.Orderscustomers.Notification'
                ),
                InvalidCartRuleDiscountValueException::INVALID_MAX_AMOUNT => $this->trans(
                    'Discount value cannot exceed the total price of this order.',
                    [],
                    'Admin.Orderscustomers.Notification'
                ),
                InvalidCartRuleDiscountValueException::INVALID_FREE_SHIPPING => $this->trans(
                    'Shipping discount value cannot exceed the total price of this order.',
                    [],
                    'Admin.Orderscustomers.Notification'
                ),
            ],
            InvalidCancelProductException::class => [
                InvalidCancelProductException::INVALID_QUANTITY => $this->trans(
                    'Positive product quantity is required.',
                    [],
                    'Admin.Notifications.Error'
                ),
                InvalidCancelProductException::QUANTITY_TOO_HIGH => $this->trans(
                    'Please enter a maximum quantity of [1].',
                    ['[1]' => $refundableQuantity],
                    'Admin.Orderscustomers.Notification'
                ),
                InvalidCancelProductException::NO_REFUNDS => $this->trans(
                    'Please select at least one product.',
                    [],
                    'Admin.Orderscustomers.Notification'
                ),
                InvalidCancelProductException::INVALID_AMOUNT => $this->trans(
                    'Please enter a positive amount.',
                    [],
                    'Admin.Orderscustomers.Notification'
                ),
                InvalidCancelProductException::NO_GENERATION => $this->trans(
                    'Please generate at least one credit slip or voucher.',
                    [],
                    'Admin.Orderscustomers.Notification'
                ),
            ],
            InvalidModuleException::class => $this->trans(
                'You must choose a payment module to create the order.',
                [],
                'Admin.Orderscustomers.Notification'
            ),
            ProductOutOfStockException::class => $this->trans(
                'There are not enough products in stock.',
                [],
                'Admin.Catalog.Notification'
            ),
            NegativePaymentAmountException::class => $this->trans(
                'Invalid value: the payment must be a positive amount.',
                [],
                'Admin.Notifications.Error'
            ),
            InvalidOrderStateException::class => [
                InvalidOrderStateException::ALREADY_PAID => $this->trans(
                    'Invalid action: this order has already been paid.',
                    [],
                    'Admin.Notifications.Error'
                ),
                InvalidOrderStateException::DELIVERY_NOT_FOUND => $this->trans(
                    'Invalid action: this order has not been delivered.',
                    [],
                    'Admin.Notifications.Error'
                ),
                InvalidOrderStateException::UNEXPECTED_DELIVERY => $this->trans(
                    'Invalid action: this order has already been delivered.',
                    [],
                    'Admin.Notifications.Error'
                ),
                InvalidOrderStateException::NOT_PAID => $this->trans(
                    'Invalid action: this order has not been paid.',
                    [],
                    'Admin.Notifications.Error'
                ),
                InvalidOrderStateException::INVALID_ID => $this->trans(
                    'You must choose an order status to create the order.',
                    [],
                    'Admin.Orderscustomers.Notification'
                ),
            ],

            OrderConstraintException::class => [
                OrderConstraintException::INVALID_CUSTOMER_MESSAGE => $this->trans(
                    'The order message given is invalid.',
                    [],
                    'Admin.Orderscustomers.Notification'
                ),
            ],
            InvalidProductQuantityException::class => $this->trans(
                'Positive product quantity is required.',
                [],
                'Admin.Notifications.Error'
            ),
            DuplicateProductInOrderException::class => $this->trans(
                'This product is already in your order, please edit the quantity instead.',
                [],
                'Admin.Notifications.Error'
            ),
            DuplicateProductInOrderInvoiceException::class => $this->trans(
                'This product is already in the invoice [1], please edit the quantity instead.',
                ['[1]' => $orderInvoiceNumber],
                'Admin.Notifications.Error'
            ),
            CannotFindProductInOrderException::class => $this->trans(
                'You cannot edit the price of a product that no longer exists in your catalog.',
                [],
                'Admin.Notifications.Error'
            ),
        ];
    }

    private function getPaymentErrorMessages(Exception $e)
    {
        return array_merge($this->getErrorMessages($e), [
            InvalidArgumentException::class => $this->trans(
                'Only numbers and decimal points (".") are allowed in the amount fields of the payment block, e.g. 10.50 or 1050.',
                [],
                'Admin.Orderscustomers.Notification'
            ),
            OrderConstraintException::class => [
                OrderConstraintException::INVALID_PAYMENT_METHOD => sprintf(
                    '%s %s %s',
                    $this->trans(
                        'The selected payment method is invalid.',
                        [],
                        'Admin.Orderscustomers.Notification'
                    ),
                    $this->trans(
                        'Invalid characters:',
                        [],
                        'Admin.Notifications.Info'
                    ),
                    AddPaymentCommand::INVALID_CHARACTERS_NAME
                ),
            ],
        ]);
    }

    /**
     * @param ChangeOrderStatusException $e
     */
    private function handleChangeOrderStatusException(ChangeOrderStatusException $e)
    {
        $orderIds = array_merge(
            $e->getOrdersWithFailedToUpdateStatus(),
            $e->getOrdersWithFailedToSendEmail()
        );

        /** @var OrderId $orderId */
        foreach ($orderIds as $orderId) {
            $this->addFlash(
                'error',
                $this->trans(
                    'An error occurred while changing the status for order #%d, or we were unable to send an email to the customer.',
                    ['#%d' => $orderId->getValue()],
                    'Admin.Orderscustomers.Notification'
                )
            );
        }

        foreach ($e->getOrdersWithAssignedStatus() as $orderId) {
            $this->addFlash(
                'error',
                $this->trans(
                    'Order #%d has already been assigned this status.',
                    ['#%d' => $orderId->getValue()],
                    'Admin.Orderscustomers.Notification'
                )
            );
        }
    }

    private function getCustomerMessageErrorMapping(Exception $exception): array
    {
        return [
            OrderNotFoundException::class => $exception instanceof OrderNotFoundException ?
                $this->trans(
                    'Order #%d cannot be loaded.',
                    ['#%d' => $exception->getOrderId()->getValue()],
                    'Admin.Orderscustomers.Notification'
                ) : '',
            CustomerMessageConstraintException::class => [
                CustomerMessageConstraintException::MISSING_MESSAGE => $this->trans(
                    'The %s field is not valid',
                    [
                        sprintf('"%s"', $this->trans('Message', [], 'Admin.Global')),
                    ],
                    'Admin.Notifications.Error'
                ),
                CustomerMessageConstraintException::INVALID_MESSAGE => $this->trans(
                    'The %s field is not valid',
                    [
                        sprintf('"%s"', $this->trans('Message', [], 'Admin.Global')),
                    ],
                    'Admin.Notifications.Error'
                ),
            ],
        ];
    }

    private function orderHasShipment(int $orderId): bool
    {
        /** @var OrderShipment[] $shipments */
        $shipments = $this->dispatchQuery(new GetOrderShipments($orderId));

        return (bool) count($shipments) > 0;
    }
}
