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

use Bolt\Boltpay\Api\UpdateCartInterface;
use Bolt\Boltpay\Model\Api\UpdateCartContext;
use Bolt\Boltpay\Model\Api\UpdateDiscountTrait;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Bolt\Boltpay\Model\ErrorResponse as BoltErrorResponse;
use Magento\Framework\Webapi\Exception as WebApiException;

/**
 * Discount Code Validation class
 * @api
 */
class UpdateCart extends UpdateCartCommon implements UpdateCartInterface
{
    use UpdateDiscountTrait { __construct as private UpdateDiscountTraitConstructor; }
    
    /**
     * UpdateCart constructor.
     *
     * @param UpdateCartContext $updateCartContext
     */
    final public function __construct(
        UpdateCartContext $updateCartContext
    ) {
        parent::__construct($updateCartContext);
        $this->UpdateDiscountTraitConstructor($updateCartContext);
    }

    /**
     * @api
     * @return bool
     * @throws \Exception
     */
    public function execute()
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
            $this->setShipment($request, $immutableQuote);
            
            // Add discounts
            if( !empty($request->discount_codes_to_add) ){
                // get the coupon code
                $discount_code = ($request->discount_codes_to_add)[0];
                $couponCode = trim($discount_code);
                
                $result = $this->verifyCouponCode($couponCode, $websiteId, $storeId);
                if( ! $result ){
                    return false;
                }
    
                list($coupon, $giftCard) = $result;                
    
                $result = $this->applyDiscount($couponCode, $coupon, $giftCard, $immutableQuote, $parentQuote);
    
                if (!$result || (isset($result['status']) && $result['status'] === 'error')) {
                    // Already sent a response with error, so just return.
                    return false;
                }
                
                $result = array();
                $result['status'] = 'success';
                $result['order_reference'] = $request->order_reference;
                $result['order_create'] = array();
                $result['order_create']['cart'] = $this->getCartData($immutableQuote);
                $result['order_create']['cart']['discounts'] = $this->getQuoteDiscounts($parentQuote);
                
                $this->sendSuccessResponse($result, $immutableQuote, $request);
            }
            
            // Remove discounts
            if( !empty($request->discount_codes_to_remove) ){
                $discount_code = ($request->discount_codes_to_remove)[0];
                $couponCode = trim($discount_code);
                
                $discounts = $this->getAppliedDiscounts($immutableQuote);
                if( ! $discounts ){
                    return false;
                }
                
                $result = $this->removeDiscount($couponCode, $discounts, $parentQuote, $immutableQuote, $websiteId, $storeId);
                
                $result = array();
                $result['status'] = 'success';
                $result['order_reference'] = $request->order_reference;
                $result['order_create'] = array();
                $result['order_create']['cart'] = $this->getCartData($immutableQuote);
                $result['order_create']['cart']['discounts'] = $this->getQuoteDiscounts($parentQuote);
            }            
            
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
    
    protected function getQuoteDiscounts($quote)
    {
        $request = $this->getRequestContent();
        $is_has_shipment = isset($request->cart->shipments[0]->reference);
        list ($discounts, ,) = $this->cartHelper->collectDiscounts(0, 0, $is_has_shipment, $quote);
        return $discounts;
    }
    
    /**
     * @param Quote $quote
     * @return array
     * @throws \Exception
     */
    protected function getCartData($quote)
    {
        $request = $this->getRequestContent();
        $is_has_shipment = isset($request->cart->shipments[0]->reference);
        $cart = $this->cartHelper->getCartData($is_has_shipment, null, $quote);
        return $cart;
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
            $additionalErrorResponseData['cart'] = $this->getCartData($quote);
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
        $this->response->setBody(json_encode($result));
        $this->response->sendResponse();

        $this->logHelper->addInfoLog('### sendSuccessResponse');
        $this->logHelper->addInfoLog(json_encode($result));
        $this->logHelper->addInfoLog('=== END ===');

        return $result;
    }
    
}
