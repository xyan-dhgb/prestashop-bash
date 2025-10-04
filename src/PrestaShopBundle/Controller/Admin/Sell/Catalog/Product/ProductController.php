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

declare(strict_types=1);

namespace PrestaShopBundle\Controller\Admin\Sell\Catalog\Product;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use HelperTreeCategories;
use PrestaShop\PrestaShop\Adapter\Category\CategoryDataProvider;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PrestaShop\PrestaShop\Adapter\Module\ModuleDataProvider;
use PrestaShop\PrestaShop\Adapter\Product\Repository\ProductRepository;
use PrestaShop\PrestaShop\Adapter\Shop\Url\ProductPreviewProvider;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\BulkDeleteProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\BulkDuplicateProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\BulkUpdateProductStatusCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\DeleteProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\DuplicateProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\UpdateProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\UpdateProductsPositionsCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\BulkProductException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\CannotBulkDeleteProductException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\CannotDeleteProductException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\InvalidProductTypeException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\ProductConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\ProductException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\ProductNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Product\FeatureValue\Exception\DuplicateFeatureValueAssociationException;
use PrestaShop\PrestaShop\Core\Domain\Product\FeatureValue\Exception\InvalidAssociatedFeatureException;
use PrestaShop\PrestaShop\Core\Domain\Product\Query\GetProductForEditing;
use PrestaShop\PrestaShop\Core\Domain\Product\Query\SearchProductsForAssociation;
use PrestaShop\PrestaShop\Core\Domain\Product\QueryResult\ProductForAssociation;
use PrestaShop\PrestaShop\Core\Domain\Product\QueryResult\ProductForEditing;
use PrestaShop\PrestaShop\Core\Domain\Product\SpecificPrice\Exception\SpecificPriceConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductId;
use PrestaShop\PrestaShop\Core\Domain\Shop\Exception\ShopAssociationNotFound;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopId;
use PrestaShop\PrestaShop\Core\Exception\MultiShopAccessDeniedException;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Builder\FormBuilderInterface;
use PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Handler\FormHandlerInterface;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\GridDefinitionFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\ProductGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShop\PrestaShop\Core\Language\LanguageRepositoryInterface;
use PrestaShop\PrestaShop\Core\Search\Filters\ProductFilters;
use PrestaShop\PrestaShop\Core\Security\Permission;
use PrestaShopBundle\Component\CsvResponse;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Controller\BulkActionsTrait;
use PrestaShopBundle\Entity\AdminFilter;
use PrestaShopBundle\Entity\ProductDownload;
use PrestaShopBundle\Entity\Repository\AdminFilterRepository;
use PrestaShopBundle\Form\Admin\Sell\Product\Category\CategoryFilterType;
use PrestaShopBundle\Form\Admin\Type\ShopSelectorType;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use PrestaShopBundle\Security\Attribute\DemoRestricted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Admin controller for the Product pages using the Symfony architecture:
 * - product list (display, search)
 * - product form (creation, edition)
 * - ...
 *
 * Some component displayed in this form are based on ajax request which might implemented
 * in another Controller.
 *
 * This controller is a re-migration of the initial ProductController which was the first
 * one to be migrated but doesn't meet the standards of the recently migrated controller.
 * The retro-compatibility is dropped for the legacy Admin pages, the former hook are no longer
 * managed for backward compatibility, new hooks need to be used in the modules, migration process
 * is detailed in the devdoc. (@todo add devdoc link when ready?)
 */
class ProductController extends PrestaShopAdminController
{
    use BulkActionsTrait;

    /**
     * Used to validate connected user authorizations.
     */
    private const PRODUCT_CONTROLLER_PERMISSION = 'ADMINPRODUCTS_';

    /**
     * Request key to retrieve product ids for various bulk actions
     */
    private const BULK_PRODUCT_IDS_KEY = 'product_bulk';

    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [
            ProductRepository::class => ProductRepository::class,
            EntityManagerInterface::class => EntityManagerInterface::class,
            LegacyContext::class => LegacyContext::class,
            AdminFilterRepository::class => AdminFilterRepository::class,
            ModuleDataProvider::class => ModuleDataProvider::class,
        ];
    }

    /**
     * Shows products listing.
     *
     * @param Request $request
     * @param ProductFilters $filters
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller')) || is_granted('update', request.get('_legacy_controller')) || is_granted('read', request.get('_legacy_controller'))")]
    public function indexAction(
        Request $request,
        #[Autowire(service: 'prestashop.core.grid.factory.product')]
        GridFactoryInterface $productGridFactory,
        ProductFilters $filters
    ): Response {
        $productGrid = $productGridFactory->getGrid($filters);

        $filteredCategoryId = null;
        if (isset($filters->getFilters()['id_category'])) {
            $filteredCategoryId = (int) $filters->getFilters()['id_category'];
        }
        $categoriesForm = $this->createForm(CategoryFilterType::class, $filteredCategoryId, [
            'action' => $this->generateUrl('admin_products_grid_category_filter'),
        ]);

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/index.html.twig', [
            'categoryFilterForm' => $categoriesForm->createView(),
            'productGrid' => $this->presentGrid($productGrid),
            'enableSidebar' => true,
            'layoutHeaderToolbarBtn' => $this->getProductToolbarButtons($request->get('_legacy_controller')),
            'help_link' => $this->generateSidebarLink('AdminProducts'),
            'layoutTitle' => $this->trans('Products', [], 'Admin.Navigation.Menu'),
        ]);
    }

    /**
     * This action is only used to allow backward compatible use of the former route admin_product_catalog
     * It is added out of courtesy to give time for module to change and use the new admin_products_index route,
     * but it will be removed in version 10.0 and its only usable via GET method.
     *
     * @deprecated Will be removed in 10.0
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller')) || is_granted('update', request.get('_legacy_controller')) || is_granted('read', request.get('_legacy_controller'))")]
    public function backwardCompatibleListAction(): RedirectResponse
    {
        return $this->redirectToRoute('admin_products_index');
    }

    /**
     * Process Grid search, but we need to add the category filter which is handled independently.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller')) || is_granted('update', request.get('_legacy_controller')) || is_granted('read', request.get('_legacy_controller'))")]
    public function searchGridAction(
        Request $request,
        #[Autowire(service: 'prestashop.core.grid.definition.factory.product')]
        GridDefinitionFactoryInterface $definitionFactory
    ): RedirectResponse {
        $filterId = ProductGridDefinitionFactory::GRID_ID;

        $adminFilter = $this->getGridAdminFilter();
        if (isset($adminFilter)) {
            $currentFilters = json_decode($adminFilter->getFilter(), true);
            if (!empty($currentFilters['filters']['id_category'])) {
                $request->query->add([
                    'product[filters][id_category]' => $currentFilters['filters']['id_category'],
                ]);
            }
        }

        return $this->buildSearchResponse(
            $definitionFactory,
            $request,
            $filterId,
            'admin_products_index',
            ['product[filters][id_category]']
        );
    }

    /**
     * Reset filters for the grid only (category is kept, it can be cleared via another dedicated action)
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller')) || is_granted('update', request.get('_legacy_controller')) || is_granted('read', request.get('_legacy_controller'))")]
    public function resetGridSearchAction(): JsonResponse
    {
        $adminFilter = $this->getGridAdminFilter();
        if (isset($adminFilter)) {
            $currentFilters = json_decode($adminFilter->getFilter(), true);

            // This reset action only reset the filters from the Grid, we keep the filter by category if it was present (we still reset to page 1 though)
            if (!empty($currentFilters['filters']['id_category'])) {
                $adminFilter->setFilter(json_encode([
                    'filters' => [
                        'id_category' => $currentFilters['filters']['id_category'],
                    ],
                    'offset' => 0,
                ]));
                $this->container->get(AdminFilterRepository::class)->updateFilter($adminFilter);
            } else {
                $this->container->get(AdminFilterRepository::class)->unsetFilters($adminFilter);
            }
        }

        return new JsonResponse();
    }

    /**
     * Apply the category filter and redirect to list on first page.
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller')) || is_granted('update', request.get('_legacy_controller')) || is_granted('read', request.get('_legacy_controller'))")]
    public function gridCategoryFilterAction(Request $request): RedirectResponse
    {
        $filteredCategoryId = $request->request->get('category_filter');
        $adminFilter = $this->getGridAdminFilter();
        if (isset($adminFilter)) {
            $currentFilters = json_decode($adminFilter->getFilter(), true);
            if (empty($filteredCategoryId)) {
                unset($currentFilters['filters']['id_category']);
            } else {
                $currentFilters['filters']['id_category'] = $filteredCategoryId;
            }
            $currentFilters['offset'] = 0;
            $adminFilter->setFilter(json_encode($currentFilters));
            $this->container->get(AdminFilterRepository::class)->updateFilter($adminFilter);
        }

        return $this->redirectToRoute('admin_products_index');
    }

    /**
     * Shows products shop details.
     *
     * @param ProductFilters $filters
     * @param int $productId
     * @param int|null $shopGroupId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller')) || is_granted('update', request.get('_legacy_controller')) || is_granted('read', request.get('_legacy_controller'))")]
    public function productShopPreviewsAction(
        ProductFilters $filters,
        int $productId,
        ?int $shopGroupId,
        #[Autowire(service: 'prestashop.core.grid.factory.product.shops')]
        GridFactoryInterface $gridFactory
    ): Response {
        $shopConstraint = !empty($shopGroupId) ? ShopConstraint::shopGroup($shopGroupId) : ShopConstraint::allShops();
        $filters = new ProductFilters(
            $shopConstraint,
            [
                'filters' => [
                    'id_product' => [
                        'min_field' => $productId,
                        'max_field' => $productId,
                    ],
                ],
            ],
            $filters->getFilterId()
        );
        $grid = $gridFactory->getGrid($filters);

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/shop_previews.html.twig', [
            'shopDetailsGrid' => $this->presentGrid($grid),
        ]);
    }

    /**
     * @return Response
     */
    #[AdminSecurity("is_granted('read', 'AdminProducts')")]
    public function lightListAction(
        ProductFilters $filters,
        Request $request,
        #[Autowire(service: 'prestashop.core.grid.factory.product_light')]
        GridFactoryInterface $gridFactory
    ): Response {
        $grid = $gridFactory->getGrid($filters);

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/light_list.html.twig', [
            'lightDisplay' => $request->query->has('liteDisplaying'),
            'productLightGrid' => $this->presentGrid($grid),
        ]);
    }

    /**
     * The redirection URL is generation thanks to the ProductPreviewProvider however it can't be used in the grid
     * since the LinkRowAction expects a symfony route, so this action is merely used as a proxy for symfony routing
     * and redirects to the appropriate product preview url.
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('read', 'AdminProducts')")]
    public function previewAction(
        int $productId,
        ?int $shopId,
        #[Autowire(service: 'prestashop.adapter.shop.url.product_preview_provider')]
        ProductPreviewProvider $previewUrlProvider
    ): RedirectResponse {
        $shopConstraint = !empty($shopId) ? ShopConstraint::shop($shopId) : ShopConstraint::allShops();
        /** @var ProductForEditing $productForEditing */
        $productForEditing = $this->dispatchQuery(new GetProductForEditing(
            $productId,
            $shopConstraint,
            $this->getLanguageContext()->getId()
        ));

        if (null === $shopId) {
            $productRepository = $this->container->get(ProductRepository::class);
            $shopId = $productRepository->getProductDefaultShopId(new ProductId($productId))->getValue();
        }

        $previewUrl = $previewUrlProvider->getUrl($productId, $productForEditing->isActive(), $shopId);

        return $this->redirect($previewUrl);
    }

    /**
     * @param Request $request
     * @param int $productId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to create this.')]
    public function selectProductShopsAction(
        Request $request,
        int $productId,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.product_shops_form_builder')]
        FormBuilderInterface $productShopsFormBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.product_shops_form_handler')]
        FormHandlerInterface $productShopsFormHandler
    ): Response {
        if (!$this->getShopContext()->getShopConstraint()->isSingleShopContext()) {
            return $this->renderIncompatibleContext($productId);
        }

        $productShopsForm = $productShopsFormBuilder->getFormFor($productId);

        try {
            $productShopsForm->handleRequest($request);

            $result = $productShopsFormHandler->handleFor($productId, $productShopsForm);

            if ($result->isSubmitted() && $result->isValid()) {
                $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));

                $redirectParams = ['productId' => $productId];
                if ($request->query->has('liteDisplaying')) {
                    $redirectParams['liteDisplaying'] = true;
                }

                return $this->redirectToRoute('admin_products_select_shops', $redirectParams);
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->renderProductShopsForm($productShopsForm, $productId, $request->query->has('liteDisplaying'));
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to create this.')]
    public function createAction(
        Request $request,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.create_product_form_builder')]
        FormBuilderInterface $productFormBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.product_form_handler')]
        FormHandlerInterface $productFormHandler
    ): Response {
        if ($request->query->has('shopId')) {
            $data['shop_id'] = $request->query->get('shopId');
        } else {
            $data['shop_id'] = $this->getShopContext()->getId();
        }
        $productForm = $productFormBuilder->getForm($data);

        try {
            $productForm->handleRequest($request);

            $result = $productFormHandler->handle($productForm);

            if ($result->isSubmitted() && $result->isValid()) {
                $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));

                $redirectParams = ['productId' => $result->getIdentifiableObjectId()];

                $createdData = $productForm->getData();
                if (!empty($createdData['shop_id'])) {
                    $this->addFlash('success', $this->trans('Your store context has been automatically modified.', [], 'Admin.Notifications.Success'));

                    // Force shop context switching to selected shop for creation (handled in admin-dev/init.php and/or AdminController)
                    $redirectParams['setShopContext'] = 's-' . $createdData['shop_id'];
                }

                // When this configuration is enabled we pre-fill the online status in the redirected form
                if ((bool) $this->getConfiguration()->get('PS_PRODUCT_ACTIVATION_DEFAULT')) {
                    $redirectParams['forceDefaultActive'] = 1;
                }

                return $this->redirectToRoute('admin_products_edit', $redirectParams);
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->renderCreateProductForm($productForm, $request->query->has('liteDisplaying'));
    }

    /**
     * @param Request $request
     * @param int $productId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to update this.', redirectRoute: 'admin_products_index')]
    public function editAction(
        Request $request,
        int $productId,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.edit_product_form_builder')]
        FormBuilderInterface $editProductFormBuilder,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.product_form_handler')]
        FormHandlerInterface $productFormHandler,
        #[Autowire(service: 'prestashop.core.form.identifiable_object.builder.category_tree_selector_form_builder')]
        FormBuilderInterface $categoryTreeFormBuilder,
    ): Response {
        if ($request->query->get('switchToShop')) {
            $this->addFlash('success', $this->trans('Your store context has been automatically modified.', [], 'Admin.Notifications.Success'));

            return $this->redirectToRoute('admin_products_edit', [
                'productId' => $productId,
                // Force shop context switching to selected shop for creation (handled in admin-dev/init.php and/or AdminController)
                'setShopContext' => 's-' . $request->query->get('switchToShop'),
            ]);
        }

        if (!$this->getShopContext()->getShopConstraint()->isSingleShopContext()) {
            return $this->renderIncompatibleContext($productId);
        }

        // When query parameter is present we force the initial value in the form, but only in GET method, or you could never manually set false in the form on submit
        $forceDefaultActive = $request->query->getBoolean('forceDefaultActive') && $request->isMethod(Request::METHOD_GET);

        try {
            $productForm = $editProductFormBuilder->getFormFor($productId, [], [
                'product_id' => $productId,
                'shop_id' => (int) $this->getShopContext()->getId(),
                'force_default_active' => $forceDefaultActive,
                // @todo: patch/partial update doesn't work good for now (especially multiple empty values) so we use POST for now
                // 'method' => Request::METHOD_PATCH,
                'method' => Request::METHOD_POST,
            ]);
        } catch (ShopAssociationNotFound $e) {
            return $this->renderMissingAssociation($productId);
        } catch (ProductNotFoundException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));

            return $this->redirectToRoute('admin_products_index');
        }

        try {
            $productForm->handleRequest($request);
            $result = $productFormHandler->handleFor($productId, $productForm);

            if ($result->isSubmitted()) {
                if ($result->isValid()) {
                    $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));

                    return $this->redirectToRoute('admin_products_edit', ['productId' => $productId]);
                } else {
                    // Display root level errors with flash messages
                    foreach ($productForm->getErrors() as $error) {
                        $this->addFlash('error', sprintf(
                            '%s: %s',
                            $error->getOrigin()->getName(),
                            $error->getMessage()
                        ));
                    }
                }
            }
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->renderEditProductForm($productForm, $productId, $categoryTreeFormBuilder);
    }

    /**
     * This action is only used to allow backward compatible use of the former route admin_product_form
     * It is added out of courtesy to give time for module to change and use the new admin_products_edit route,
     * but it will be removed in version 10.0 and its only usable via GET method.
     *
     * @deprecated Will be removed in 10.0
     *
     * @param int $id
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to update this.')]
    public function backwardCompatibleEditAction(int $id): RedirectResponse
    {
        return $this->redirectToRoute('admin_products_edit', ['productId' => $id]);
    }

    /**
     * @param int $productId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function deleteFromAllShopsAction(int $productId): Response
    {
        try {
            $shopConstraint = ShopConstraint::allShops();
            if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
                throw new MultiShopAccessDeniedException($shopConstraint);
            }

            $this->dispatchCommand(new DeleteProductCommand($productId, $shopConstraint));
            $this->addFlash(
                'success',
                $this->trans('Successful deletion', [], 'Admin.Notifications.Success')
            );
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->redirectToRoute('admin_products_index');
    }

    /**
     * @param int $productId
     * @param int $shopId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function deleteFromShopAction(int $productId, int $shopId): Response
    {
        try {
            $shopConstraint = ShopConstraint::shop($shopId);
            if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
                throw new MultiShopAccessDeniedException($shopConstraint);
            }

            $this->dispatchCommand(new DeleteProductCommand($productId, $shopConstraint));
            $this->addFlash(
                'success',
                $this->trans('Successful deletion', [], 'Admin.Notifications.Success')
            );
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->redirectToRoute('admin_products_index');
    }

    /**
     * @param int $productId
     * @param int $shopGroupId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function deleteFromShopGroupAction(int $productId, int $shopGroupId): Response
    {
        try {
            $shopConstraint = ShopConstraint::shopGroup($shopGroupId);
            if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
                throw new MultiShopAccessDeniedException($shopConstraint);
            }

            $this->dispatchCommand(new DeleteProductCommand($productId, $shopConstraint));
            $this->addFlash(
                'success',
                $this->trans('Successful deletion', [], 'Admin.Notifications.Success')
            );
        } catch (ProductException $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->redirectToRoute('admin_products_index');
    }

    /**
     * @param int $shopId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.', jsonResponse: true)]
    public function bulkDeleteFromShopAction(Request $request, int $shopId): Response
    {
        $shopConstraint = ShopConstraint::shop($shopId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkDeleteByShopConstraint($request, $shopConstraint);
    }

    /**
     * @param int $shopGroupId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.', jsonResponse: true)]
    public function bulkDeleteFromShopGroupAction(Request $request, int $shopGroupId): Response
    {
        $shopConstraint = ShopConstraint::shopGroup($shopGroupId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkDeleteByShopConstraint($request, $shopConstraint);
    }

    /**
     * @param int $productId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to create this.')]
    public function duplicateAllShopsAction(int $productId): Response
    {
        $shopConstraint = ShopConstraint::allShops();
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->duplicateByShopConstraint($productId, $shopConstraint);
    }

    /**
     * @param int $productId
     * @param int $shopId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to create this.')]
    public function duplicateShopAction(int $productId, int $shopId): Response
    {
        $shopConstraint = ShopConstraint::shop($shopId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->duplicateByShopConstraint($productId, $shopConstraint);
    }

    /**
     * @param int $productId
     * @param int $shopGroupId
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to create this.')]
    public function duplicateShopGroupAction(int $productId, int $shopGroupId): Response
    {
        $shopConstraint = ShopConstraint::shopGroup($shopGroupId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->duplicateByShopConstraint($productId, $shopConstraint);
    }

    /**
     * Toggles product status for specific shop
     *
     * @param int $productId
     * @param int $shopId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index')]
    public function toggleStatusForShopAction(int $productId, int $shopId): JsonResponse
    {
        $shopConstraint = ShopConstraint::shop($shopId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->toggleProductStatusByShopConstraint($productId, $shopConstraint);
    }

    /**
     * Toggles product status for all shops
     *
     * @param int $productId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index')]
    public function toggleStatusForAllShopsAction(int $productId): JsonResponse
    {
        $shopConstraint = ShopConstraint::allShops();
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->toggleProductStatusByShopConstraint($productId, $shopConstraint);
    }

    /**
     * Enable product status for all shops and redirect to product list.
     *
     * @param int $productId
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index')]
    public function enableForAllShopsAction(int $productId): RedirectResponse
    {
        $shopConstraint = ShopConstraint::allShops();
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->updateProductStatusByShopConstraint($productId, true, $shopConstraint);
    }

    /**
     * Disable product status for all shops and redirect to product list.
     *
     * @param int $productId
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index')]
    public function disableForAllShopsAction(int $productId): RedirectResponse
    {
        $shopConstraint = ShopConstraint::allShops();
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->updateProductStatusByShopConstraint($productId, false, $shopConstraint);
    }

    /**
     * Enable product status for shop group and redirect to product list.
     *
     * @param int $productId
     * @param int $shopGroupId
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index')]
    public function enableForShopGroupAction(int $productId, int $shopGroupId): RedirectResponse
    {
        $shopConstraint = ShopConstraint::shopGroup($shopGroupId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->updateProductStatusByShopConstraint($productId, true, $shopConstraint);
    }

    /**
     * Disable product status for shop group and redirect to product list.
     *
     * @param int $productId
     * @param int $shopGroupId
     *
     * @return RedirectResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index')]
    public function disableForShopGroupAction(int $productId, int $shopGroupId): RedirectResponse
    {
        $shopConstraint = ShopConstraint::shopGroup($shopGroupId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->updateProductStatusByShopConstraint($productId, false, $shopConstraint);
    }

    /**
     * Export filtered products
     *
     * @param ProductFilters $filters
     *
     * @return CsvResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index')]
    public function exportAction(
        ProductFilters $filters,
        #[Autowire(service: 'prestashop.core.grid.factory.product')]
        GridFactoryInterface $productGridFactory,
    ): CsvResponse {
        $filters = new ProductFilters($filters->getShopConstraint(), ['limit' => null] + $filters->all());
        $grid = $productGridFactory->getGrid($filters);

        $headers = [
            'id_product' => 'Product ID',
            'image_link' => $this->trans('Image', [], 'Admin.Global'),
            'name' => $this->trans('Name', [], 'Admin.Global'),
            'reference' => $this->trans('Reference', [], 'Admin.Global'),
            'name_category' => $this->trans('Category', [], 'Admin.Global'),
            'price' => $this->trans('Price (tax excl.)', [], 'Admin.Catalog.Feature'),
            'price_final' => $this->trans('Price (tax incl.)', [], 'Admin.Catalog.Feature'),
            'sav_quantity' => $this->trans('Quantity', [], 'Admin.Global'),
        ];

        $data = [];

        foreach ($grid->getData()->getRecords()->all() as $record) {
            $data[] = [
                'id_product' => $record['id_product'],
                'image_link' => $record['image'],
                'name' => $record['name'],
                'reference' => $record['reference'],
                'name_category' => $record['category'],
                'price' => $record['final_price_tax_excluded'],
                'price_final' => $record['price_tax_included'],
                'sav_quantity' => $record['quantity'],
            ];
        }

        return (new CsvResponse())
            ->setData($data)
            ->setHeadersData($headers)
            ->setFileName('product_' . date('Y-m-d_His') . '.csv');
    }

    /**
     * Updates product position.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[DemoRestricted(redirectRoute: 'admin_products_index', redirectQueryParamsToKeep: ['id_category'])]
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', redirectQueryParamsToKeep: ['id_category'], message: 'You do not have permission to edit this.')]
    public function updatePositionAction(Request $request): RedirectResponse
    {
        try {
            $this->dispatchCommand(
                new UpdateProductsPositionsCommand(
                    $request->request->all('positions'),
                    $request->query->getInt('id_category')
                )
            );
            $this->addFlash('success', $this->trans('Update successful', [], 'Admin.Notifications.Success'));
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));

            return $this->redirectToRoute('admin_products_index');
        }

        return $this->redirectToRoute('admin_products_index');
    }

    /**
     * Delete products in bulk action.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', message: 'You do not have permission to delete this.')]
    public function bulkDeleteFromAllShopsAction(Request $request): JsonResponse
    {
        try {
            $shopConstraint = ShopConstraint::allShops();
            if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
                throw new MultiShopAccessDeniedException($shopConstraint);
            }

            $this->bulkDeleteByShopConstraint($request, $shopConstraint);
            $this->addFlash(
                'success',
                $this->trans('Successful deletion', [], 'Admin.Notifications.Success')
            );
        } catch (Exception $e) {
            if ($e instanceof BulkProductException) {
                return $this->jsonBulkErrors($e);
            } else {
                return $this->json(['error' => $this->getErrorMessageForException($e, $this->getErrorMessages())], Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->json(['success' => true]);
    }

    /**
     * Enable products in bulk action.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', message: 'You do not have permission to edit this.', jsonResponse: true)]
    public function bulkEnableAllShopsAction(Request $request): JsonResponse
    {
        $shopConstraint = ShopConstraint::allShops();
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkUpdateProductStatus($request, true, $shopConstraint);
    }

    /**
     * Enable products in bulk action for a specific shop.
     *
     * @param Request $request
     * @param int $shopId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', message: 'You do not have permission to edit this.', jsonResponse: true)]
    public function bulkEnableShopAction(Request $request, int $shopId): JsonResponse
    {
        $shopConstraint = ShopConstraint::shop($shopId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkUpdateProductStatus($request, true, $shopConstraint);
    }

    /**
     * Enable products in bulk action for a specific shop group.
     *
     * @param Request $request
     * @param int $shopGroupId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', message: 'You do not have permission to edit this.', jsonResponse: true)]
    public function bulkEnableShopGroupAction(Request $request, int $shopGroupId): JsonResponse
    {
        $shopConstraint = ShopConstraint::shopGroup($shopGroupId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkUpdateProductStatus($request, true, $shopConstraint);
    }

    /**
     * Disable products in bulk action.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', message: 'You do not have permission to edit this.', jsonResponse: true)]
    public function bulkDisableAllShopsAction(Request $request): JsonResponse
    {
        $shopConstraint = ShopConstraint::allShops();
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkUpdateProductStatus($request, false, $shopConstraint);
    }

    /**
     * Disable products in bulk action for a specific shop.
     *
     * @param Request $request
     * @param int $shopId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', message: 'You do not have permission to edit this.', jsonResponse: true)]
    public function bulkDisableShopAction(Request $request, int $shopId): JsonResponse
    {
        $shopConstraint = ShopConstraint::shop($shopId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkUpdateProductStatus($request, false, $shopConstraint);
    }

    /**
     * Disable products in bulk action for a specific shop group.
     *
     * @param Request $request
     * @param int $shopGroupId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', message: 'You do not have permission to edit this.', jsonResponse: true)]
    public function bulkDisableShopGroupAction(Request $request, int $shopGroupId): JsonResponse
    {
        $shopConstraint = ShopConstraint::shopGroup($shopGroupId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkUpdateProductStatus($request, false, $shopConstraint);
    }

    /**
     * Duplicate products in bulk action.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', message: 'You do not have permission to edit this.', jsonResponse: true)]
    public function bulkDuplicateAllShopsAction(Request $request): JsonResponse
    {
        $shopConstraint = ShopConstraint::allShops();
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkDuplicateByShopConstraint($request, $shopConstraint);
    }

    /**
     * Duplicate products in bulk action for specific shop.
     *
     * @param Request $request
     * @param int $shopId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', message: 'You do not have permission to edit this.', jsonResponse: true)]
    public function bulkDuplicateShopAction(Request $request, int $shopId): JsonResponse
    {
        $shopConstraint = ShopConstraint::shop($shopId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkDuplicateByShopConstraint($request, $shopConstraint);
    }

    /**
     * Duplicate products in bulk action for specific shop group.
     *
     * @param Request $request
     * @param int $shopGroupId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", redirectRoute: 'admin_products_index', message: 'You do not have permission to edit this.', jsonResponse: true)]
    public function bulkDuplicateShopGroupAction(Request $request, int $shopGroupId): JsonResponse
    {
        $shopConstraint = ShopConstraint::shopGroup($shopGroupId);
        if (!$this->hasAuthorizationByShopConstraint($shopConstraint)) {
            throw new MultiShopAccessDeniedException($shopConstraint);
        }

        return $this->bulkDuplicateByShopConstraint($request, $shopConstraint);
    }

    /**
     * Download the content of the virtual product.
     *
     * @param int $virtualProductFileId
     *
     * @return BinaryFileResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to read this.')]
    public function downloadVirtualFileAction(int $virtualProductFileId): BinaryFileResponse
    {
        $em = $this->container->get(EntityManagerInterface::class);
        $configuration = $this->getConfiguration();
        $download = $em->getRepository(ProductDownload::class)
            ->findOneBy([
                'id' => $virtualProductFileId,
            ]);

        $response = new BinaryFileResponse(
            $configuration->get('_PS_DOWNLOAD_DIR_') . $download->getFilename()
        );

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $download->getDisplayFilename()
        );

        return $response;
    }

    /**
     * @param Request $request
     * @param string $languageCode
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function searchProductsForAssociationAction(
        Request $request,
        string $languageCode,
        LanguageRepositoryInterface $langRepository
    ): JsonResponse {
        $lang = $langRepository->getOneByLocaleOrIsoCode($languageCode);
        if (null === $lang) {
            return $this->json([
                'message' => sprintf(
                    'Invalid language code %s was used which matches no existing language in this shop.',
                    $languageCode
                ),
            ], Response::HTTP_BAD_REQUEST);
        }

        $shopId = $this->getShopContext()->getId();
        if (empty($shopId)) {
            $shopId = (int) $this->getConfiguration()->get('PS_SHOP_DEFAULT');
        }

        try {
            /** @var ProductForAssociation[] $products */
            $products = $this->dispatchQuery(new SearchProductsForAssociation(
                $request->get('query', ''),
                $lang->getId(),
                (int) $shopId,
                (int) $request->get('limit', 20)
            ));
        } catch (ProductConstraintException $e) {
            return $this->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($products)) {
            return $this->json([], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->formatProductsForAssociation($products));
    }

    /**
     * @param int $productId
     * @param int $shopId
     *
     * @return JsonResponse
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function quantityAction(
        int $productId,
        int $shopId,
    ): JsonResponse {
        /** @var ProductForEditing $productForEditing */
        $productForEditing = $this->dispatchQuery(
            new GetProductForEditing($productId, ShopConstraint::shop($shopId), $this->getLanguageContext()->getId())
        );

        return $this->json(['quantity' => $productForEditing->getStockInformation()->getQuantity()]);
    }

    /**
     * @param ProductForAssociation[] $productsForAssociation
     *
     * @return array
     */
    private function formatProductsForAssociation(array $productsForAssociation): array
    {
        $productsData = [];
        foreach ($productsForAssociation as $productForAssociation) {
            $productName = $productForAssociation->getName();
            if (!empty($productForAssociation->getReference())) {
                $productName .= sprintf(' (ref: %s)', $productForAssociation->getReference());
            }

            $productsData[] = [
                'id' => $productForAssociation->getProductId(),
                'name' => $productName,
                'image' => $productForAssociation->getImageUrl(),
                'product_type' => $productForAssociation->getProductType(),
            ];
        }

        return $productsData;
    }

    /**
     * @param FormInterface $productForm
     *
     * @return Response
     */
    private function renderCreateProductForm(FormInterface $productForm, bool $lightDisplay): Response
    {
        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/create.html.twig', [
            'lightDisplay' => $lightDisplay,
            'showContentHeader' => false,
            'productForm' => $productForm->createView(),
            'helpLink' => $this->generateSidebarLink('AdminProducts'),
            'editable' => $this->isGranted(Permission::UPDATE, self::PRODUCT_CONTROLLER_PERMISSION),
        ]);
    }

    /**
     * @param FormInterface $productForm
     * @param int $productId
     * @param FormBuilderInterface $categoryTreeFormBuilder
     *
     * @return Response
     */
    private function renderEditProductForm(FormInterface $productForm, int $productId, FormBuilderInterface $categoryTreeFormBuilder): Response
    {
        $configuration = $this->getConfiguration();

        $statsModule = $this->container->get(ModuleDataProvider::class)->findByName('statsproduct');
        $statsLink = null;
        if (!empty($statsModule['active'])) {
            $legacyContext = $this->container->get(LegacyContext::class);
            $statsLink = $legacyContext->getAdminLink('AdminStats', true, ['module' => 'statsproduct', 'id_product' => $productId]);
        }

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/edit.html.twig', [
            'categoryTreeSelectorForm' => $categoryTreeFormBuilder->getForm()->createView(),
            'showContentHeader' => false,
            'productForm' => $productForm->createView(),
            'statsLink' => $statsLink,
            'helpLink' => $this->generateSidebarLink('AdminProducts'),
            'editable' => $this->isGranted(Permission::UPDATE, self::PRODUCT_CONTROLLER_PERMISSION),
            'taxEnabled' => (bool) $configuration->get('PS_TAX'),
            'stockEnabled' => (bool) $configuration->get('PS_STOCK_MANAGEMENT'),
            'isMultistoreActive' => $this->getShopContext()->isMultiShopEnabled(),
            'layoutTitle' => $this->trans('Product', [], 'Admin.Global'),
        ]);
    }

    /**
     * @param FormInterface $productShopsForm
     *
     * @return Response
     */
    private function renderProductShopsForm(FormInterface $productShopsForm, int $productId, bool $lightDisplay): Response
    {
        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/shops.html.twig', [
            'productId' => $productId,
            'lightDisplay' => $lightDisplay,
            'showContentHeader' => false,
            'productShopsForm' => $productShopsForm->createView(),
            'helpLink' => $this->generateSidebarLink('AdminProducts'),
        ]);
    }

    /**
     * Helper private method to duplicate some products
     *
     * @param Request $request
     * @param ShopConstraint $shopConstraint
     *
     * @return JsonResponse
     */
    private function bulkDuplicateByShopConstraint(Request $request, ShopConstraint $shopConstraint): JsonResponse
    {
        try {
            $this->dispatchCommand(
                new BulkDuplicateProductCommand(
                    $this->getBulkActionIds($request, self::BULK_PRODUCT_IDS_KEY),
                    $shopConstraint
                )
            );
        } catch (Exception $e) {
            if ($e instanceof BulkProductException) {
                return $this->jsonBulkErrors($e);
            } else {
                return $this->json(['error' => $this->getErrorMessageForException($e, $this->getErrorMessages())], Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->json(['success' => true]);
    }

    /**
     * Helper private method to duplicate a product
     *
     * @param int $productId
     * @param ShopConstraint $shopConstraint
     *
     * @return Response
     */
    private function duplicateByShopConstraint(int $productId, ShopConstraint $shopConstraint): Response
    {
        try {
            /** @var ProductId $newProductId */
            $newProductId = $this->dispatchCommand(new DuplicateProductCommand(
                $productId,
                $shopConstraint
            ));
            $this->addFlash(
                'success',
                $this->trans('Successful duplication', [], 'Admin.Notifications.Success')
            );
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));

            return $this->redirectToRoute('admin_products_index');
        }

        return $this->redirectToRoute('admin_products_edit', ['productId' => $newProductId->getValue()]);
    }

    /**
     * Helper private method to delete some products
     *
     * @param Request $request
     * @param ShopConstraint $shopConstraint
     *
     * @return JsonResponse
     */
    private function bulkDeleteByShopConstraint(Request $request, ShopConstraint $shopConstraint): JsonResponse
    {
        try {
            $this->dispatchCommand(new BulkDeleteProductCommand(
                $this->getBulkActionIds($request, self::BULK_PRODUCT_IDS_KEY),
                $shopConstraint
            ));
            $this->addFlash(
                'success',
                $this->trans('Successful deletion', [], 'Admin.Notifications.Success')
            );
        } catch (Exception $e) {
            if ($e instanceof BulkProductException) {
                return $this->jsonBulkErrors($e);
            } else {
                return $this->json(['error' => $this->getErrorMessageForException($e, $this->getErrorMessages())], Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->json(['success' => true]);
    }

    /**
     * @param string $securitySubject
     *
     * @return array<string, array<string, mixed>>
     */
    private function getProductToolbarButtons(string $securitySubject): array
    {
        $toolbarButtons = [];

        // do not show create button if user has no permissions for it
        if (!$this->isGranted(Permission::CREATE, $securitySubject)) {
            return $toolbarButtons;
        }

        $toolbarButtons['add'] = [
            'href' => $this->generateUrl('admin_products_create', ['shopId' => $this->getShopIdFromShopContext()]),
            'desc' => $this->trans('Add new product', [], 'Admin.Actions'),
            'icon' => 'add_circle_outline',
            'class' => 'btn-primary new-product-button',
            'floating_class' => 'new-product-button',
            'data_attributes' => [
                'modal-title' => $this->trans('Add new product', [], 'Admin.Catalog.Feature'),
            ],
        ];

        return $toolbarButtons;
    }

    /**
     * Helper private method to update a product's status.
     *
     * @param int $productId
     * @param bool $isEnabled
     * @param ShopConstraint $shopConstraint
     *
     * @return RedirectResponse
     */
    private function updateProductStatusByShopConstraint(int $productId, bool $isEnabled, ShopConstraint $shopConstraint): RedirectResponse
    {
        try {
            $command = new UpdateProductCommand($productId, $shopConstraint);
            $command->setActive($isEnabled);
            $this->dispatchCommand($command);
            $this->addFlash('success', $this->trans('The status has been successfully updated.', [], 'Admin.Notifications.Success'));
        } catch (Exception $e) {
            $this->addFlash('error', $this->getErrorMessageForException($e, $this->getErrorMessages()));
        }

        return $this->redirectToRoute('admin_products_index');
    }

    private function toggleProductStatusByShopConstraint(int $productId, ShopConstraint $shopConstraint): JsonResponse
    {
        /** @var ProductForEditing $productForEditing */
        $productForEditing = $this->dispatchQuery(new GetProductForEditing(
            $productId,
            $shopConstraint,
            $this->getLanguageContext()->getId()
        ));

        try {
            $command = new UpdateProductCommand($productId, $shopConstraint);
            $command->setActive(!$productForEditing->isActive());
            $this->dispatchCommand($command);
        } catch (Exception $e) {
            return $this->json([
                'status' => false,
                'message' => $this->getErrorMessageForException($e, $this->getErrorMessages()),
            ]);
        }

        return $this->json([
            'status' => true,
            'message' => $this->trans('The status has been successfully updated.', [], 'Admin.Notifications.Success'),
        ]);
    }

    /**
     * Helper private method to bulk update a product's status.
     *
     * @param Request $request
     * @param bool $newStatus
     * @param ShopConstraint $shopConstraint
     *
     * @return JsonResponse
     */
    private function bulkUpdateProductStatus(Request $request, bool $newStatus, ShopConstraint $shopConstraint): JsonResponse
    {
        try {
            $this->dispatchCommand(
                new BulkUpdateProductStatusCommand(
                    $this->getBulkActionIds($request, self::BULK_PRODUCT_IDS_KEY),
                    $newStatus,
                    $shopConstraint
                )
            );
        } catch (Exception $e) {
            if ($e instanceof BulkProductException) {
                return $this->jsonBulkErrors($e);
            } else {
                return $this->json(['error' => $this->getErrorMessageForException($e, $this->getErrorMessages())], Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->json(['success' => true]);
    }

    /**
     * Format the bulk exception into an array of errors returned in a JsonResponse.
     *
     * @param BulkProductException $bulkProductException
     *
     * @return JsonResponse
     */
    private function jsonBulkErrors(BulkProductException $bulkProductException): JsonResponse
    {
        $errors = [];
        foreach ($bulkProductException->getBulkExceptions() as $productId => $productException) {
            $errors[] = $this->trans(
                'Error for product %product_id%: %error_message%',
                [
                    '%product_id%' => $productId,
                    '%error_message%' => $this->getErrorMessageForException($productException, $this->getErrorMessages()),
                ],
                'Admin.Catalog.Notification',
            );
        }

        return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Gets an error by exception class and its code.
     *
     * @return array
     */
    private function getErrorMessages(): array
    {
        return [
            CannotDeleteProductException::class => $this->trans(
                'An error occurred while deleting the object.',
                [],
                'Admin.Notifications.Error'
            ),
            CannotBulkDeleteProductException::class => $this->trans(
                'An error occurred while deleting this selection.',
                [],
                'Admin.Notifications.Error'
            ),
            ProductConstraintException::class => [
                ProductConstraintException::INVALID_PRICE => $this->trans(
                    'Product price is invalid.',
                    [],
                    'Admin.Notifications.Error'
                ),
                ProductConstraintException::INVALID_UNIT_PRICE => $this->trans(
                    'Product price per unit is invalid.',
                    [],
                    'Admin.Notifications.Error'
                ),
                ProductConstraintException::INVALID_REDIRECT_TARGET => $this->trans(
                    'Product "Redirection when offline" target is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_ONLINE_DATA => $this->trans(
                    'Product doesn\'t have the minimum data to go online.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_AVAILABLE_FOR_ORDER => $this->trans(
                    'Product "Available for order" settings is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_REDIRECT_TYPE => $this->trans(
                    'Product "Redirection when offline" behavior is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_SHOW_PRICE => $this->trans(
                    'Product "Show price" settings is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_ONLINE_ONLY => $this->trans(
                    'Product "Web only" settings is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_ADDITIONAL_SHIPPING_COST => $this->trans(
                    'Product additional shipping cost is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_AVAILABLE_DATE => $this->trans(
                    'Product availability date is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_AVAILABLE_NOW => $this->trans(
                    'Product availability label when in stock is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_AVAILABLE_LATER => $this->trans(
                    'Product availability label when out of stock is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_CONDITION => $this->trans(
                    'Product condition is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_CUSTOMIZABILITY => $this->trans(
                    'Product customization fields are invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_DELIVERY_TIME_IN_STOCK_NOTES => $this->trans(
                    'Product delivery time when in stock are invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_ADDITIONAL_DELIVERY_TIME_NOTES_TYPE => $this->trans(
                    'Product delivery times are invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_ADDITIONAL_TIME_NOTES_TYPE => $this->trans(
                    'Product delivery times are invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_DELIVERY_TIME_OUT_OF_STOCK_NOTES => $this->trans(
                    'Product delivery times when out of stock are invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_DEPTH => $this->trans(
                    'Product depth is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_DESCRIPTION => $this->trans(
                    'Product description is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_EAN_13 => $this->trans(
                    'Product EAN13 field is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_ECOTAX => $this->trans(
                    'Product ecotax is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_LINK_REWRITE => $this->trans(
                    'Product friendly URL is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_GTIN => $this->trans(
                    'Product GTIN field is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_HEIGHT => $this->trans(
                    'Product height is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_ID => $this->trans(
                    'Product ID is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_ISBN => $this->trans(
                    'Product ISBN field is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_MANUFACTURER_ID => $this->trans(
                    'Product manufacturer is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_META_DESCRIPTION => $this->trans(
                    'Product meta description is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_META_TITLE => $this->trans(
                    'Product meta title is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_MINIMAL_QUANTITY => $this->trans(
                    'Product minimum quantity for sale is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_MPN => $this->trans(
                    'Product MPN field is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_NAME => $this->trans(
                    'Product name is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_REFERENCE => $this->trans(
                    'Product reference is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_SHORT_DESCRIPTION => $this->trans(
                    'Product short description is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_PRODUCT_TYPE => $this->trans(
                    'Product type is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_VISIBILITY => $this->trans(
                    'Product visibility settings is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_WEIGHT => $this->trans(
                    'Product weight is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_WIDTH => $this->trans(
                    'Product width is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_LOW_STOCK_ALERT => $this->trans(
                    'Product "Low stock alert" settings is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_LOW_STOCK_THRESHOLD => $this->trans(
                    'Product low stock alert treshold is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_SEARCH_LIMIT => $this->trans(
                    'Search phrase limit is not valid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_SEARCH_PHRASE_LENGTH => $this->trans(
                    'Search phrase length is not valid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_SHOW_CONDITION => $this->trans(
                    'Product "Show condition" settings is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_STATUS => $this->trans(
                    'Product status (active/inactive) is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_TAG => $this->trans(
                    'Product tags are invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_TEXT_FIELDS_COUNT => $this->trans(
                    'Product text customization fields are invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_UNITY => $this->trans(
                    'Product unit in "price per unit" is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_UPC => $this->trans(
                    'Product UPC field is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_UPLOADABLE_FILES_COUNT => $this->trans(
                    'Product file customization fields is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
                ProductConstraintException::INVALID_WHOLESALE_PRICE => $this->trans(
                    'Product wholesale price is invalid.',
                    [],
                    'Admin.Catalog.Notification'
                ),
            ],
            DuplicateFeatureValueAssociationException::class => $this->trans(
                'You cannot associate the same feature value more than once.',
                [],
                'Admin.Notifications.Error'
            ),
            InvalidAssociatedFeatureException::class => $this->trans(
                'The selected value belongs to another feature.',
                [],
                'Admin.Notifications.Error'
            ),
            SpecificPriceConstraintException::class => [
                SpecificPriceConstraintException::DUPLICATE_PRIORITY => $this->trans(
                    'The selected condition must be different in each field to set an order of priority.',
                    [],
                    'Admin.Notifications.Error'
                ),
            ],
            InvalidProductTypeException::class => [
                InvalidProductTypeException::EXPECTED_NO_EXISTING_PACK_ASSOCIATIONS => $this->trans(
                    'This product cannot be changed into a pack because it is already associated to another pack.',
                    [],
                    'Admin.Notifications.Error'
                ),
            ],
            ProductNotFoundException::class => $this->trans(
                'The object cannot be loaded (or found).',
                [],
                'Admin.Notifications.Error'
            ),
        ];
    }

    /**
     * @param int $productId
     *
     * @return Response
     */
    private function renderMissingAssociation(int $productId): Response
    {
        return $this->renderPreSelectShopPage(
            $productId,
            $this->trans(
                'This product is not associated with the store selected in the multistore header, please select another one.',
                [],
                'Admin.Notifications.Info'
            )
        );
    }

    /**
     * @param int $productId
     *
     * @return Response
     */
    private function renderIncompatibleContext(int $productId): Response
    {
        return $this->renderPreSelectShopPage(
            $productId,
            $this->trans(
                'This page is only compatible in a single-store context. Please select a store in the multistore header.',
                [],
                'Admin.Notifications.Info'
            )
        );
    }

    /**
     * @param int $productId
     *
     * @return Response
     */
    private function renderPreSelectShopPage(int $productId, string $warningMessage): Response
    {
        $productRepository = $this->container->get(ProductRepository::class);

        return $this->render('@PrestaShop/Admin/Sell/Catalog/Product/pre_select_shop.html.twig', [
            'warningMessage' => $warningMessage,
            'showContentHeader' => false,
            'modalTitle' => $this->trans('Select a store', [], 'Admin.Catalog.Feature'),
            'shopSelectorForm' => $this->createForm(ShopSelectorType::class)->createView(),
            'productId' => $productId,
            'productShopIds' => array_map(static function (ShopId $shopId) {
                return $shopId->getValue();
            }, $productRepository->getAssociatedShopIds(new ProductId($productId))),
        ]);
    }

    private function getGridAdminFilter(): ?AdminFilter
    {
        if (null === $this->getEmployeeContext()->getEmployee()) {
            return null;
        }

        $employeeId = $this->getEmployeeContext()->getEmployee()->getId();
        $shopId = $this->getShopContext()->getId();

        return $this->container->get(AdminFilterRepository::class)
            ->findByEmployeeAndFilterId($employeeId, $shopId, ProductGridDefinitionFactory::GRID_ID);
    }

    /**
     * @return int|null
     */
    private function getShopIdFromShopContext(): ?int
    {
        $shopId = $this->getShopContext()->getId();

        return !empty($shopId) ? (int) $shopId : null;
    }

    /**
     * Displays a category tree (legacy).
     *
     * This action is kept for backward compatibility with pages
     * that still rely on HelperTreeCategories.
     *
     * @todo Remove this method once all pages depending on
     *       HelperTreeCategories have been migrated to Symfony.
     *
     * @param Request $request
     *
     * @return Response
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller')) || is_granted('update', request.get('_legacy_controller')) || is_granted('read', request.get('_legacy_controller'))")]
    public function legacyCategoryTreeAction(
        Request $request,
    ): Response {
        $contextAdapter = $this->container->get(LegacyContext::class);
        $rootCategoryId = (new CategoryDataProvider($contextAdapter))->getRootCategory()->id;
        $category = $request->query->get('category', $rootCategoryId);
        $full_tree = $request->query->get('fullTree', 0);
        $use_check_box = $request->query->get('useCheckBox', 1);
        $selected = $request->query->all('selected');
        if (is_array($selected) === false) {
            $selected = [$selected];
        }
        $id_tree = $request->query->get('type');
        $input_name = str_replace(['[', ']'], '', $request->query->get('inputName', ''));

        $tree = new HelperTreeCategories('subtree_associated_categories');
        $tree->setTemplate('subtree_associated_categories.tpl')
            ->setUseCheckBox($use_check_box)
            ->setUseSearch(true)
            ->setIdTree($id_tree)
            ->setSelectedCategories($selected)
            ->setFullTree($full_tree)
            ->setChildrenOnly(true)
            ->setNoJS(true)
            ->setRootCategory($category);

        if ($input_name) {
            $tree->setInputName($input_name);
        }

        $contextAdapter->getContext()->smarty->setTemplateDir([
            _PS_BO_ALL_THEMES_DIR_ . 'default/template/',
            _PS_OVERRIDE_DIR_ . 'controllers' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'templates',
        ]);

        return new Response($tree->render());
    }
}
