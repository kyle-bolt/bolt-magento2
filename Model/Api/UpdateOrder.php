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
 * @copyright  Copyright (c) 2019 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Model\Api;

use Bolt\Boltpay\Api\CreateOrderInterface;
use Bolt\Boltpay\Helper\Cart;
use Magento\Framework\Exception\LocalizedException;
use Bolt\Boltpay\Helper\Order as OrderHelper;
use Bolt\Boltpay\Helper\Cart as CartHelper;
use Bolt\Boltpay\Helper\Log as LogHelper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;
use Bolt\Boltpay\Helper\Hook as HookHelper;
use Bolt\Boltpay\Helper\Bugsnag;
use Magento\Framework\Webapi\Rest\Response;
use Bolt\Boltpay\Helper\Config as ConfigHelper;

/**
 * Class UPdateOrder
 * Web hook endpoint. Update the order.
 *
 * @package Bolt\Boltpay\Model\Api
 */
class UpdateOrder implements CreateOrderInterface
{
    const E_BOLT_GENERAL_ERROR = 2001001;
    const E_BOLT_ORDER_ALREADY_EXISTS = 2001002;
    const E_BOLT_CART_HAS_EXPIRED = 2001003;
    const E_BOLT_ITEM_PRICE_HAS_BEEN_UPDATED = 2001004;

    /**
     * @var HookHelper
     */
    private $hookHelper;

    /**
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     * @var LogHelper
     */
    private $logHelper;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Bugsnag
     */
    private $bugsnag;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var ConfigHelper
     */
    private $configHelper;

    /**
     * @var CartHelper
     */
    private $cartHelper;

    /**
     * @param HookHelper   $hookHelper
     * @param OrderHelper  $orderHelper
     * @param CartHelper   $cartHelper
     * @param LogHelper    $logHelper
     * @param Request      $request
     * @param Bugsnag      $bugsnag
     * @param Response     $response
     * @param ConfigHelper $configHelper
     */
    public function __construct(
        HookHelper $hookHelper,
        OrderHelper $orderHelper,
        CartHelper $cartHelper,
        LogHelper $logHelper,
        Request $request,
        Bugsnag $bugsnag,
        Response $response,
        ConfigHelper $configHelper
    ) {
        $this->hookHelper = $hookHelper;
        $this->orderHelper = $orderHelper;
        $this->logHelper = $logHelper;
        $this->request = $request;
        $this->bugsnag = $bugsnag;
        $this->response = $response;
        $this->configHelper = $configHelper;
        $this->cartHelper = $cartHelper;
    }

    /**
     * Pre-Auth hook: Update order.
     *
     * @api
     *
     * @param null $type
     * @param null $transaction
     * @param null $order_reference
     * @param null $display_id
     *
     * return void
     */
    public function execute(
        $type = null,
        $transaction = null,
        $order_reference = null,
        $display_id = null
    ) {
        try {
            if ($type !== 'order.update') {
                throw new LocalizedException(__('Invalid hook type!'));
            }

            $payload = $this->request->getContent();

            $this->validateHook();

            $this->sendResponse(200, [
                'status'    => 'success',
                'message'   => 'Order create was successful',
                'display_id' => '',
                'total'      => '',
                'order_received_url' => '',
            ]);
        } catch (\Magento\Framework\Webapi\Exception $e) {
            $this->bugsnag->notifyException($e);
            $this->sendResponse($e->getHttpCode(), [
                'status' => 'error',
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        } catch (LocalizedException $e) {
            $this->bugsnag->notifyException($e);
            $this->sendResponse(422, [
                'status' => 'error',
                'code' => '6009',
                'message' => 'Unprocessable Entity: ' . $e->getMessage(),
            ]);
        } finally {
            $this->response->sendResponse();
        }
    }

    /**
     * @throws LocalizedException
     * @throws \Magento\Framework\Webapi\Exception
     */
    public function validateHook()
    {
        HookHelper::$fromBolt = true;

        $this->hookHelper->setCommonMetaData();
        $this->hookHelper->setHeaders();

        $this->hookHelper->verifyWebhook();
    }

    /**
     * @param int   $code
     * @param array $body
     */
    public function sendResponse($code, array $body)
    {
        $this->response->setHttpResponseCode($code);
        $this->response->setBody(json_encode($body));
    }
}