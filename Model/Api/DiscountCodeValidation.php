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

namespace Bolt\Boltpay\Model\Api;

use Bolt\Boltpay\Api\DiscountCodeValidationInterface;
use Bolt\Boltpay\Model\Api\UpdateCartContext;
use Bolt\Boltpay\Model\Api\UpdateDiscountTrait;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\RuleRepository;
use Magento\SalesRule\Model\Coupon;
use Bolt\Boltpay\Helper\Log as LogHelper;
use Magento\SalesRule\Model\ResourceModel\Coupon\UsageFactory;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\SalesRule\Model\Rule\CustomerFactory;
use Bolt\Boltpay\Helper\Bugsnag;
use Bolt\Boltpay\Helper\Cart as CartHelper;
use Bolt\Boltpay\Helper\Config as ConfigHelper;
use Bolt\Boltpay\Helper\Shared\CurrencyUtils;
use Magento\Quote\Model\Quote;
use Bolt\Boltpay\Model\ThirdPartyModuleFactory;
use Magento\Framework\Webapi\Exception as WebApiException;
use Bolt\Boltpay\Model\ErrorResponse as BoltErrorResponse;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Bolt\Boltpay\Helper\Discount as DiscountHelper;
use Magento\Quote\Model\Quote\TotalsCollector;

use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use Bolt\Boltpay\Helper\Hook as HookHelper;
use Magento\Directory\Model\Region as RegionModel;
use Bolt\Boltpay\Helper\Order as OrderHelper;

/**
 * Discount Code Validation class
 * @api
 */
class DiscountCodeValidation extends UpdateCartCommon implements DiscountCodeValidationInterface
{
    use UpdateDiscountTrait;
    
    /**
     * UpdateCart constructor.
     *
     * @param UpdateCartContext $updateCartContext
     */
    public function __construct(
        UpdateCartContext $updateCartContext
    ) {
        parent::__construct($updateCartContext);
    }
    
    /**
     * @api
     * @return bool
     * @throws \Exception
     */
    public function validate()
    {    
        try {
            $request = $this->getRequestContent();
            
            $result = $this->validateQuote($request);
            
            if( ! $result ){
                return false;
            }
            
            list($parentQuoteId, $incrementId, $immutableQuoteId, $parentQuote, $immutableQuote) = $result;

            $storeId = $parentQuote->getStoreId();
            $websiteId = $parentQuote->getStore()->getWebsiteId();

            $this->preProcessWebhook($storeId);
            $parentQuote->getStore()->setCurrentCurrencyCode($parentQuote->getQuoteCurrencyCode());

            // get the coupon code
            $discount_code = @$request->discount_code ?: @$request->cart->discount_code;
            $couponCode = trim($discount_code);
            
            $result = $this->verifyCouponCode($couponCode, $websiteId, $storeId);
            if( ! $result ){
                return false;
            }

            list($coupon, $giftCard) = $result;
            
            $this->setShipment($request, $immutableQuote);

            $result = $this->applyDiscount($couponCode, $coupon, $giftCard, $immutableQuote, $parentQuote);

            if (!$result || (isset($result['status']) && $result['status'] === 'error')) {
                // Already sent a response with error, so just return.
                return false;
            }

            $this->sendSuccessResponse($result, $immutableQuote);
        } catch (WebApiException $e) {
            $this->bugsnag->notifyException($e);
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                $e->getHttpCode(),
                ($immutableQuote) ? $immutableQuote : null
            );

            return false;
        } catch (LocalizedException $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                500
            );

            return false;
        } catch (\Exception $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                500
            );

            return false;
        }

        return true;
    }
    
    /**
     * @param Quote $quote
     * @return array
     * @throws \Exception
     */
    private function getCartTotals($quote)
    {
        $request = $this->getRequestContent();
        $is_has_shipment = isset($request->cart->shipments[0]->reference);
        $cart = $this->cartHelper->getCartData($is_has_shipment, null, $quote);
        return [
            'total_amount' => $cart['total_amount'],
            'tax_amount'   => $cart['tax_amount'],
            'discounts'    => $cart['discounts'],
        ];
    }

    /**
     * @param int        $errCode
     * @param string     $message
     * @param int        $httpStatusCode
     * @param null|Quote $quote
     *
     * @return void
     * @throws \Exception
     */
    protected function sendErrorResponse($errCode, $message, $httpStatusCode, $quote = null)
    {
        $additionalErrorResponseData = [];
        if ($quote) {
            $additionalErrorResponseData['cart'] = $this->getCartTotals($quote);
        }

        $encodeErrorResult = $this->errorResponse
            ->prepareErrorMessage($errCode, $message, $additionalErrorResponseData);

        $this->logHelper->addInfoLog('### sendErrorResponse');
        $this->logHelper->addInfoLog($encodeErrorResult);

        $this->response->setHttpResponseCode($httpStatusCode);
        $this->response->setBody($encodeErrorResult);
        $this->response->sendResponse();
    }

    /**
     * @param array $result
     * @param Quote $quote
     * @return array
     * @throws \Exception
     */
    protected function sendSuccessResponse($result, $quote, $request)
    {
        $result['cart'] = $this->getCartTotals($quote);

        $this->response->setBody(json_encode($result));
        $this->response->sendResponse();

        $this->logHelper->addInfoLog('### sendSuccessResponse');
        $this->logHelper->addInfoLog(json_encode($result));
        $this->logHelper->addInfoLog('=== END ===');

        return $result;
    }

}
