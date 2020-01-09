<?php
/**
 * Bolt magento2 plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2018 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Test\Unit\Block;

use Bolt\Boltpay\Block\JsProductPage as BlockJsProductPage;
use Bolt\Boltpay\Helper\Bugsnag;
use Bolt\Boltpay\Helper\Config as HelperConfig;
use Bolt\Boltpay\Helper\Cart as CartHelper;
use Magento\Framework\App\Request\Http;
use Magento\Catalog\Block\Product\View as ProductView;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
/**
 * Class JsTest
 *
 * @package Bolt\Boltpay\Test\Unit\Block
 */
class JsProductPageTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var HelperConfig
     */
    protected $configHelper;
    /**
     * @var \Magento\Framework\App\Helper\Context
     */
    protected $helperContextMock;
    /**
     * @var \Magento\Framework\View\Element\Template\Context
     */
    protected $contextMock;

    /**
     * @var Http
     */
    private $requestMock;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSessionMock;

    /**
     * @var BlockJs
     */
    protected $block;

    /**
     * @var CartHelper
     */
    private $cartHelperMock;

    /**
     * @var Bugsnag
     */
    private $bugsnagHelperMock;

    private $magentoQuote;

    private $productViewMock;

    private $scopeConfigMock;

    private $product;

    /**
     * @inheritdoc
     */
    protected function setUp()
    {
        $this->helperContextMock = $this->createMock(\Magento\Framework\App\Helper\Context::class);
        $this->contextMock = $this->createMock(\Magento\Framework\View\Element\Template\Context::class);

        $this->checkoutSessionMock = $this->getMockBuilder(\Magento\Checkout\Model\Session::class)
            ->disableOriginalConstructor()
            ->setMethods(['getQuote', 'getBoltInitiateCheckout', 'unsBoltInitiateCheckout'])
            ->getMock();

        $this->magentoQuote = $this->getMockBuilder(\Magento\Checkout\Model\Session::class)
            ->disableOriginalConstructor()
            ->setMethods(['getQuote'])
            ->getMock();

        $methods = [
            'isSandboxModeSet', 'isActive', 'getAnyPublishableKey',
            'getPublishableKeyPayment', 'getPublishableKeyCheckout', 'getPublishableKeyBackOffice',
            'getReplaceSelectors', 'getGlobalCSS', 'getPrefetchShipping', 'getQuoteIsVirtual',
            'getTotalsChangeSelectors', 'getAdditionalCheckoutButtonClass', 'getAdditionalConfigString', 'getIsPreAuth',
            'shouldTrackCheckoutFunnel', 'isPaymentOnlyCheckoutEnabled', 'isGuestCheckoutAllowed'
        ];

        $this->configHelper = $this->getMockBuilder(HelperConfig::class)
            ->setMethods($methods)
            ->setConstructorArgs(
                [
                    $this->helperContextMock,
                    $this->createMock(\Magento\Framework\Encryption\EncryptorInterface::class),
                    $this->createMock(\Magento\Framework\Module\ResourceInterface::class),
                    $this->createMock(\Magento\Framework\App\ProductMetadataInterface::class),
                    $this->createMock(\Magento\Framework\App\Request\Http::class)
                ]
            )
            ->getMock();

        $this->cartHelperMock = $this->createMock(CartHelper::class);
        $this->bugsnagHelperMock = $this->createMock(Bugsnag::class);
        $this->requestMock = $this->getMockBuilder(Http::class)
            ->disableOriginalConstructor()
            ->setMethods(['getFullActionName'])
            ->getMock();

        $this->contextMock->method('getRequest')->willReturn($this->requestMock);

        $this->product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->setMethods(['getExtensionAttributes', 'getStockItem', 'getTypeId'])
            ->getMock();

        $this->productViewMock = $this->getMockBuilder(ProductView::class)
            ->disableOriginalConstructor()
            ->setMethods(['getProduct'])
            ->getMock();
        $this->productViewMock->method('getProduct')
            ->willReturn($this->product);

        $this->block = $this->getMockBuilder(BlockJsProductPage::class)
            ->setMethods(['configHelper', 'getUrl', 'getBoltPopupErrorMessage'])
            ->setConstructorArgs(
                [
                    $this->contextMock,
                    $this->configHelper,
                    $this->checkoutSessionMock,
                    $this->cartHelperMock,
                    $this->bugsnagHelperMock,
                    $this->productViewMock
                ]
            )
            ->getMock();
    }

    /**
     * @test
     */
    public function getProduct()
    {
        $result = $this->block->getProduct();
        $this->assertSame($this->product, $result);
    }

    /**
     * @test
     * @dataProvider providerGetProductQty
     */
    public function getProductQty($getManageStock, $getIsInStock, $getQty, $expected_result)
    {
        $stockItem = $this->getMockForAbstractClass(
            \Magento\CatalogInventory\Api\Data\StockItemInterface::class,
            [],
            '',
            false,
            true,
            true,
            ['getManageStock', 'getIsInStock', 'getQty']
        );
        $stockItem->method('getManageStock')->willReturn($getManageStock);
        $stockItem->method('getIsInStock')->willReturn($getIsInStock);
        $stockItem->method('getQty')->willReturn($getQty);

        $this->product->method('getExtensionAttributes')->willReturnSelf();
        $this->product->method('getStockItem')->willReturn($stockItem);

        $result = $this->block->getProductQty();
        $this->assertEquals($expected_result, $result);
    }

    public function providerGetProductQty()
    {
        return [
            [false, true, 1, -1],
            [true, false, 1, 0],
            [true, true, 1, 1],
            [true, true, 5, 5],
        ];
    }

    /**
     * @test
     * @dataProvider providerIsSupportableType
     */
    public function isSupportableType($typeId, $expected_result)
    {
        $this->product->method('getTypeId')->willReturn($typeId);
        $result = $this->block->isSupportableType();
        $this->assertEquals($expected_result, $result);
    }

    public function providerIsSupportableType()
    {
        return [
            ['simple', true],
            ['grouped', false],
            ['configurable', false],
            ['virtual', true],
            ['bundle', false],
            ['downloadable', false]
        ];
    }

    /**
     * @test
     * @dataProvider providerIsGuestCheckoutAllowed
     */
    public function isGuestCheckoutAllowed($flag, $expected_result)
    {
        $this->configHelper->method('isGuestCheckoutAllowed')
            ->willReturn($flag);
        $result = $this->block->isGuestCheckoutAllowed();
        $this->assertEquals($expected_result, $result);
    }

    public function providerIsGuestCheckoutAllowed() {
        return [
            [true,1],
            [false,0],
        ];
    }
}