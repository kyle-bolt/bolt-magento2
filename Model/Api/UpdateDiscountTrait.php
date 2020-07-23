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

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Bolt\Boltpay\Model\ErrorResponse as BoltErrorResponse;
use Bolt\Boltpay\Helper\Shared\CurrencyUtils;
use Magento\Quote\Model\Quote;
use Bolt\Boltpay\Model\ThirdPartyModuleFactory;

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
use Magento\Framework\Webapi\Exception as WebApiException;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Bolt\Boltpay\Helper\Discount as DiscountHelper;
use Magento\Quote\Model\Quote\TotalsCollector;
use Bolt\Boltpay\Model\Api\UpdateCartContext;

/**
 * Discount Code Validation class
 * @api
 */
trait UpdateDiscountTrait
{
    /**
     * @var CouponFactory
     */
    protected $couponFactory;

    /**
     * @var ThirdPartyModuleFactory
     */
    protected $moduleGiftCardAccount;
    
    /**
     * @var ThirdPartyModuleFactory|\Magento\GiftCardAccount\Helper\Data
     */
    protected $moduleGiftCardAccountHelper;

    /**
     * @var ThirdPartyModuleFactory
     */
    protected $moduleUnirgyGiftCert;

    /**
     * @var RuleRepository
     */
    protected $ruleRepository;

    /**
     * @var LogHelper
     */
    protected $logHelper;

    /**
     * @var UsageFactory
     */
    protected $usageFactory;

    /**
     * @var DataObjectFactory
     */
    protected $objectFactory;

    /**
     * @var TimezoneInterface
     */
    protected $timezone;

    /**
     * @var CustomerFactory
     */
    protected $customerFactory;

    /**
     * @var Bugsnag
     */
    protected $bugsnag;

    /**
     * @var CartHelper
     */
    protected $cartHelper;

    /**
     * @var ConfigHelper
     */
    protected $configHelper;

    /**
     * @var ThirdPartyModuleFactory|\Unirgy\Giftcert\Helper\Data
     */
    protected $moduleUnirgyGiftCertHelper;
    /**
     * @var QuoteRepository
     */
    protected $quoteRepositoryForUnirgyGiftCert;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSessionForUnirgyGiftCert;

    /**
     * @var DiscountHelper
     */
    protected $discountHelper;

    /**
     * @var TotalsCollector
     */
    protected $totalsCollector;
    
    protected $discountTypes = [
        DiscountHelper::AMASTY_GIFTCARD => '',
        DiscountHelper::UNIRGY_GIFT_CERT => '',
        DiscountHelper::MAGEPLAZA_GIFTCARD => '',
        DiscountHelper::GIFT_CARD_ACCOUNT => ''
    ];

    /**
     * UpdateDiscountTrait constructor.
     *
     * @param UpdateCartContext $updateCartContext
     */
    final public function __construct(
        UpdateCartContext $updateCartContext
    ) {
        $this->couponFactory = $updateCartContext->getCouponFactory();
        $this->moduleGiftCardAccount = $updateCartContext->getModuleGiftCardAccount();
        $this->moduleGiftCardAccountHelper = $updateCartContext->getModuleGiftCardAccountHelper();
        $this->moduleUnirgyGiftCert = $updateCartContext->getModuleUnirgyGiftCert();
        $this->moduleUnirgyGiftCertHelper = $updateCartContext->getModuleUnirgyGiftCertHelper();
        $this->quoteRepositoryForUnirgyGiftCert = $updateCartContext->getQuoteRepositoryForUnirgyGiftCert();
        $this->checkoutSessionForUnirgyGiftCert = $updateCartContext->getCheckoutSessionForUnirgyGiftCert();
        $this->ruleRepository = $updateCartContext->getRuleRepository();
        $this->logHelper = $updateCartContext->getLogHelper();
        $this->usageFactory = $updateCartContext->getUsageFactory();
        $this->objectFactory = $updateCartContext->getObjectFactory();
        $this->timezone = $updateCartContext->getTimezone();
        $this->customerFactory = $updateCartContext->getCustomerFactory();
        $this->bugsnag = $updateCartContext->getBugsnag();
        $this->cartHelper = $updateCartContext->getCartHelper();
        $this->configHelper = $updateCartContext->getConfigHelper();
        $this->discountHelper = $updateCartContext->getDiscountHelper();
        $this->totalsCollector = $updateCartContext->getTotalsCollector();
    }
    
    protected function verifyCouponCode( $couponCode, $websiteId, $storeId )
    {
        // Check if empty coupon was sent
        if ($couponCode === '') {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_CODE_INVALID,
                'No coupon code provided',
                422
            );

            return false;
        }

        // Load the gift card by code
        $giftCard = $this->loadGiftCardData($couponCode, $websiteId);

        // Apply Unirgy_GiftCert
        if (empty($giftCard)) {
            // Load the gift cert by code
            $giftCard = $this->loadGiftCertData($couponCode, $storeId);
        }

        // Load Amasty Gift Card account object
        if (empty($giftCard)) {
            $giftCard = $this->discountHelper->loadAmastyGiftCard($couponCode, $websiteId);
        }

        // Apply Mageplaza_GiftCard
        if (empty($giftCard)) {
            // Load the gift card by code
            $giftCard = $this->discountHelper->loadMageplazaGiftCard($couponCode, $storeId);
        }

        $coupon = null;
        if (empty($giftCard)) {
            // Load the coupon
            $coupon = $this->loadCouponCodeData($couponCode);
        }

        // Check if the coupon and gift card does not exist.
        if ((empty($coupon) || $coupon->isObjectNew()) && empty($giftCard)) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_CODE_INVALID,
                sprintf('The coupon code %s is not found', $couponCode),
                404
            );

            return false;
        }
        
        return [$coupon, $giftCard];
    }
    
    protected function applyDiscount( $couponCode, $coupon, $giftCard, $immutableQuote, $parentQuote )
    {
        if ($coupon && $coupon->getCouponId()) {
            if ($this->shouldUseParentQuoteShippingAddressDiscount($couponCode, $immutableQuote, $parentQuote)) {
                $result = $this->getParentQuoteDiscountResult($couponCode, $coupon, $parentQuote);
            } else {
                $result = $this->applyingCouponCode($couponCode, $coupon, $immutableQuote, $parentQuote);
            }
        } elseif ($giftCard && $giftCard->getId()) {
            $result = $this->applyingGiftCardCode($couponCode, $giftCard, $immutableQuote, $parentQuote);
        } else {
            throw new WebApiException(__('Something happened with current code.'));
        }
        
        return $result;
    }

    /**
     * Applying coupon code to immutable and parent quote.
     *
     * @param string $couponCode
     * @param Coupon $coupon
     * @param Quote  $immutableQuote
     * @param Quote  $parentQuote
     *
     * @return array|false
     * @throws LocalizedException
     * @throws \Exception
     */
    private function applyingCouponCode($couponCode, $coupon, $immutableQuote, $parentQuote)
    {
        // get coupon entity id and load the coupon discount rule
        $couponId = $coupon->getId();
        try {
            /** @var \Magento\SalesRule\Model\Rule $rule */
            $rule = $this->ruleRepository->getById($coupon->getRuleId());
        } catch (NoSuchEntityException $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_CODE_INVALID,
                sprintf('The coupon code %s is not found', $couponCode),
                404
            );

             return false;
        }
        $websiteId = $parentQuote->getStore()->getWebsiteId();
        $ruleWebsiteIDs = $rule->getWebsiteIds();

        if (!in_array($websiteId, $ruleWebsiteIDs)) {
            $this->logHelper->addInfoLog('Error: coupon from another website.');
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_CODE_INVALID,
                sprintf('The coupon code %s is not found', $couponCode),
                404
            );

            return false;
        }

        // get the rule id
        $ruleId = $rule->getRuleId();

        // Check date validity if "To" date is set for the rule
        $date = $rule->getToDate();
        if ($date && date('Y-m-d', strtotime($date)) < date('Y-m-d')) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_CODE_EXPIRED,
                sprintf('The code [%s] has expired.', $couponCode),
                422,
                $immutableQuote
            );

            return false;
        }

        // Check date validity if "From" date is set for the rule
        $date = $rule->getFromDate();
        if ($date && date('Y-m-d', strtotime($date)) > date('Y-m-d')) {
            $desc = 'Code available from ' . $this->timezone->formatDate(
                new \DateTime($rule->getFromDate()),
                \IntlDateFormatter::MEDIUM
            );
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_CODE_NOT_AVAILABLE,
                $desc,
                422,
                $immutableQuote
            );

            return false;
        }

        // Check coupon usage limits.
        if ($coupon->getUsageLimit() && $coupon->getTimesUsed() >= $coupon->getUsageLimit()) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_CODE_LIMIT_REACHED,
                sprintf('The code [%s] has exceeded usage limit.', $couponCode),
                422,
                $immutableQuote
            );

            return false;
        }

        // Check per customer usage limits
        if ($customerId = $immutableQuote->getCustomerId()) {
            // coupon per customer usage
            if ($usagePerCustomer = $coupon->getUsagePerCustomer()) {
                $couponUsage = $this->objectFactory->create();
                $this->usageFactory->create()->loadByCustomerCoupon(
                    $couponUsage,
                    $customerId,
                    $couponId
                );
                if ($couponUsage->getCouponId() && $couponUsage->getTimesUsed() >= $usagePerCustomer) {
                    $this->sendErrorResponse(
                        BoltErrorResponse::ERR_CODE_LIMIT_REACHED,
                        sprintf('The code [%s] has exceeded usage limit.', $couponCode),
                        422,
                        $immutableQuote
                    );

                    return false;
                }
            }
            // rule per customer usage
            if ($usesPerCustomer = $rule->getUsesPerCustomer()) {
                $ruleCustomer = $this->customerFactory->create()->loadByCustomerRule($customerId, $ruleId);
                if ($ruleCustomer->getId() && $ruleCustomer->getTimesUsed() >= $usesPerCustomer) {
                    $this->sendErrorResponse(
                        BoltErrorResponse::ERR_CODE_LIMIT_REACHED,
                        sprintf('The code [%s] has exceeded usage limit.', $couponCode),
                        422,
                        $immutableQuote
                    );

                    return false;
                }
            }
        }

        try {
            // try applying to parent first
            $this->setCouponCode($parentQuote, $couponCode);
            // apply coupon to clone
            $this->setCouponCode($immutableQuote, $couponCode);
        } catch (\Exception $e) {
            $this->bugsnag->notifyException($e);
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                422,
                $immutableQuote
            );

            return false;
        }

        if ($immutableQuote->getCouponCode() != $couponCode) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                __('Coupon code does not equal with a quote code!'),
                422,
                $immutableQuote
            );
            return false;
        }

        $address = $immutableQuote->isVirtual() ?
            $immutableQuote->getBillingAddress() :
            $immutableQuote->getShippingAddress();
        $this->totalsCollector->collectAddressTotals($immutableQuote, $address);

        $result = [
            'status'          => 'success',
            'discount_code'   => $couponCode,
            'discount_amount' => abs(CurrencyUtils::toMinor($address->getDiscountAmount(), $immutableQuote->getQuoteCurrencyCode())),
            'description'     => trim(__('Discount ') . $rule->getDescription()),
            'discount_type'   => $this->convertToBoltDiscountType($rule->getSimpleAction()),
        ];

        $this->logHelper->addInfoLog('### Coupon Result');
        $this->logHelper->addInfoLog(json_encode($result));

        return $result;
    }

    /**
     * @param $code
     * @param \Magento\GiftCardAccount\Model\Giftcardaccount|\Unirgy\Giftcert\Model\Cert $giftCard
     * @param Quote $immutableQuote
     * @param Quote $parentQuote
     * @return array
     * @throws \Exception
     */
    private function applyingGiftCardCode($code, $giftCard, $immutableQuote, $parentQuote)
    {
        try {
            if ($giftCard instanceof \Amasty\GiftCard\Model\Account || $giftCard instanceof \Amasty\GiftCardAccount\Model\GiftCardAccount\Account) {
                // Remove Amasty Gift Card if already applied
                // to avoid errors on multiple calls to discount validation API
                // from the Bolt checkout (changing the address, going back and forth)
                $this->discountHelper->removeAmastyGiftCard($code, $parentQuote);
                // Apply Amasty Gift Card to the parent quote
                $giftAmount = $this->discountHelper->applyAmastyGiftCard($code, $giftCard, $parentQuote);
                // Reset and apply Amasty Gift Cards to the immutable quote
                $this->discountHelper->cloneAmastyGiftCards($parentQuote->getId(), $immutableQuote->getId());
            } elseif ($giftCard instanceof \Unirgy\Giftcert\Model\Cert) {
                /** @var \Unirgy\Giftcert\Helper\Data $unirgyHelper */
                $unirgyHelper = $this->moduleUnirgyGiftCertHelper->getInstance();
                /** @var CheckoutSession $checkoutSession */
                $checkoutSession = $this->checkoutSessionForUnirgyGiftCert;

                if (empty($immutableQuote->getData($giftCard::GIFTCERT_CODE))) {
                    $unirgyHelper->addCertificate(
                        $giftCard->getCertNumber(),
                        $immutableQuote,
                        $this->quoteRepositoryForUnirgyGiftCert
                    );
                }

                if (empty($parentQuote->getData($giftCard::GIFTCERT_CODE))) {
                    $unirgyHelper->addCertificate(
                        $giftCard->getCertNumber(),
                        $parentQuote,
                        $this->quoteRepositoryForUnirgyGiftCert
                    );
                }

                // The Unirgy_GiftCert require double call the function addCertificate().
                // Look on Unirgy/Giftcert/Controller/Checkout/Add::execute()
                $unirgyHelper->addCertificate(
                    $giftCard->getCertNumber(),
                    $checkoutSession->getQuote(),
                    $this->quoteRepositoryForUnirgyGiftCert
                );

                $giftAmount = $giftCard->getBalance();
            } elseif ($giftCard instanceof \Mageplaza\GiftCard\Model\GiftCard) {
                // Remove Mageplaza Gift Card if it was already applied
                // to avoid errors on multiple calls to the discount validation API
                // (e.g. changing the address, going back and forth)
                $this->discountHelper->removeMageplazaGiftCard($giftCard->getId(), $immutableQuote);
                $this->discountHelper->removeMageplazaGiftCard($giftCard->getId(), $parentQuote);

                // Apply Mageplaza Gift Card to the parent quote
                $this->discountHelper->applyMageplazaGiftCard($code, $immutableQuote);
                $this->discountHelper->applyMageplazaGiftCard($code, $parentQuote);

                $giftAmount = $giftCard->getBalance();
            } else {
                if ($immutableQuote->getGiftCardsAmountUsed() == 0) {
                    try {
                        // on subsequest validation calls from Bolt checkout
                        // try removing the gift card before adding it
                        $giftCard->removeFromCart(true, $immutableQuote);
                    } catch (\Exception $e) {
                        // gift card not added yet
                    } finally {
                        $giftCard->addToCart(true, $immutableQuote);
                    }
                }

                if ($parentQuote->getGiftCardsAmountUsed() == 0) {
                    try {
                        // on subsequest validation calls from Bolt checkout
                        // try removing the gift card before adding it
                        $giftCard->removeFromCart(true, $parentQuote);
                    } catch (\Exception $e) {
                        // gift card not added yet
                    } finally {
                        $giftCard->addToCart(true, $parentQuote);
                    }
                }

                // Send the whole GiftCard Amount.
                $giftAmount = $parentQuote->getGiftCardsAmount();
            }
        } catch (\Exception $e) {
            $this->bugsnag->notifyException($e);
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                422,
                $immutableQuote
            );

            return false;
        }

        $result = [
            'status'          => 'success',
            'discount_code'   => $code,
            'discount_amount' => abs(CurrencyUtils::toMinor($giftAmount, $immutableQuote->getQuoteCurrencyCode())),
            'description'     =>  __('Gift Card'),
            'discount_type'   => $this->convertToBoltDiscountType('by_fixed'),
        ];

        $this->logHelper->addInfoLog('### Gift Card Result');
        $this->logHelper->addInfoLog(json_encode($result));

        return $result;
    }

    /**
     * @param string $type
     * @return string
     */
    private function convertToBoltDiscountType($type)
    {
        switch ($type) {
            case "by_fixed":
            case "cart_fixed":
                return "fixed_amount";
            case "by_percent":
                return "percentage";
            case "by_shipping":
                return "shipping";
        }

        return "";
    }

    /**
     * Set applied coupon code
     *
     * @param Quote  $quote
     * @param string $couponCode
     * @throws \Exception
     */
    private function setCouponCode($quote, $couponCode)
    {
        $quote->getShippingAddress()->setCollectShippingRates(true);
        $quote->setCouponCode($couponCode)->collectTotals()->save();
    }

    /**
     * Load the coupon data by code
     *
     * @param $couponCode
     *
     * @return Coupon
     */
    private function loadCouponCodeData($couponCode)
    {
        return $this->couponFactory->create()->loadByCode($couponCode);
    }

    /**
     * Load the gift card data by code
     *
     * @param string $code
     * @param string|int $websiteId
     *
     * @return \Magento\GiftCardAccount\Model\Giftcardaccount|null
     */
    public function loadGiftCardData($code, $websiteId)
    {
        $result = null;

        /** @var \Magento\GiftCardAccount\Model\ResourceModel\Giftcardaccount\Collection $giftCardAccountResource */
        $giftCardAccountResource = $this->moduleGiftCardAccount->getInstance();

        if ($giftCardAccountResource) {
            $this->logHelper->addInfoLog('### GiftCard ###');
            $this->logHelper->addInfoLog('# Code: ' . $code);

            /** @var \Magento\GiftCardAccount\Model\ResourceModel\Giftcardaccount\Collection $giftCardsCollection */
            $giftCardsCollection = $giftCardAccountResource
                ->addFieldToFilter('code', ['eq' => $code])
                ->addWebsiteFilter([0, $websiteId]);

            /** @var \Magento\GiftCardAccount\Model\Giftcardaccount $giftCard */
            $giftCard = $giftCardsCollection->getFirstItem();

            $result = (!$giftCard->isEmpty() && $giftCard->isValid()) ? $giftCard : null;
        }

        $this->logHelper->addInfoLog('# loadGiftCertData Result is empty: '. ((!$result) ? 'yes' : 'no'));

        return $result;
    }

    /**
     * Load the Unirgy gift cert data by code
     * 
     * @param string $code
     * @param string|int $storeId
     *
     * @return null|\Unirgy\Giftcert\Model\Cert
     * @throws NoSuchEntityException
     */
    public function loadGiftCertData($code, $storeId)
    {
        $result = null;

        /** @var \Unirgy\Giftcert\Model\GiftcertRepository $giftCertRepository */
        $giftCertRepository = $this->moduleUnirgyGiftCert->getInstance();

        if ($giftCertRepository) {
            $this->logHelper->addInfoLog('### GiftCert ###');
            $this->logHelper->addInfoLog('# Code: ' . $code);

            try {
                /** @var \Unirgy\Giftcert\Model\Cert $giftCert */
                $giftCert = $giftCertRepository->get($code);

                $gcStoreId = $giftCert->getStoreId();

                $result = ((!$gcStoreId || $gcStoreId == $storeId) && $giftCert->getData('status') === 'A')
                          ? $giftCert : null;

            } catch (NoSuchEntityException $e) {
                //We must ignore the exception, because it is thrown when data does not exist.
                $result = null;
            }
        }

        $this->logHelper->addInfoLog('# loadGiftCertData Result is empty: ' . ((!$result) ? 'yes' : 'no'));

        return $result;
    }

    /**
     * Check if address doesn't allow discount code
     *
     * @param string $couponCode
     * @param Quote  $immutableQuote
     * @param Quote  $parentQuote
     *
     * @return bool
     */
    protected function shouldUseParentQuoteShippingAddressDiscount(
        $couponCode,
        Quote $immutableQuote,
        Quote $parentQuote
    ) {
        $ignoredShippingAddressCoupons = $this->configHelper->getIgnoredShippingAddressCoupons(
            $parentQuote->getStoreId()
        );

        return $immutableQuote->getCouponCode() == $couponCode &&
               $immutableQuote->getCouponCode() == $parentQuote->getCouponCode() &&
               in_array($couponCode, $ignoredShippingAddressCoupons);
    }

    /**
     * @param string $couponCode
     * @param Quote  $parentQuote
     * @param Coupon $coupon
     *
     * @return array|false
     * @throws \Exception
     */
    protected function getParentQuoteDiscountResult($couponCode, $coupon, $parentQuote)
    {
        try {
            // Load the coupon discount rule
            $rule = $this->ruleRepository->getById($coupon->getRuleId());
        } catch (NoSuchEntityException $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_CODE_INVALID,
                sprintf('The coupon code %s is not found', $couponCode),
                404
            );

            return false;
        }

        $address = $this->cartHelper->getCalculationAddress($parentQuote);

        return $result = [
            'status'          => 'success',
            'discount_code'   => $couponCode,
            'discount_amount' => abs(CurrencyUtils::toMinor($address->getDiscountAmount(), $parentQuote->getQuoteCurrencyCode())),
            'description'     =>  __('Discount ') . $address->getDiscountDescription(),
            'discount_type'   => $this->convertToBoltDiscountType($rule->getSimpleAction()),
        ];
    }
    
    /**
     * Get the array list of applied discounts
     *
     * @param Quote  $quote
     *
     * @return array|false
     * @throws \Exception
     */
    protected function getAppliedDiscounts($quote)
    {
        $totals = $quote->getTotals();
        $address = $this->cartHelper->getCalculationAddress($quote);
        $discounts = [];
        
        try{
            if ( $couponCode = $address->getCouponCode() ) {
                $discounts[$couponCode] = 'internal_coupon';
            }
            
            foreach ($this->discountTypes as $discount => $description) {
                if (!empty($totals[$discount]) && $amount = $totals[$discount]->getValue()) {
                    if ($discount == DiscountHelper::AMASTY_GIFTCARD && $this->discountHelper->getAmastyPayForEverything()) {
                        $giftCardCodes = $this->discountHelper->getAmastyGiftCardCodesFromTotals($totals);
                        foreach($giftCardCodes as $giftCardCode){
                            $discounts[$giftCardCode] = DiscountHelper::AMASTY_GIFTCARD;    
                        }                    
                    }
                    
                    if ($discount == DiscountHelper::MAGEPLAZA_GIFTCARD) {
                        $giftCardCodes = $this->discountHelper->getMageplazaGiftCardCodes($quote);
                        foreach($giftCardCodes as $giftCardCode){
                            $discounts[$giftCardCode] = DiscountHelper::MAGEPLAZA_GIFTCARD;    
                        }
                    }
                    
                    if ($discount == DiscountHelper::UNIRGY_GIFT_CERT && $quote->getData('giftcert_code')) {
                        $giftCardCodes = $quote->getData('giftcert_code');
                        foreach($giftCardCodes as $giftCardCode){
                            $discounts[$giftCardCode] = DiscountHelper::UNIRGY_GIFT_CERT;    
                        }
                    }
                    
                    if ($discount == DiscountHelper::GIFT_CARD_ACCOUNT) {
                        $giftCardCodes = $this->moduleGiftCardAccountHelper->getCards($quote);
                        foreach ($giftCardCodes as $giftCardCode) {
                            $discounts[$giftCardCode[\Magento\GiftCardAccount\Model\Giftcardaccount::CODE]] = DiscountHelper::GIFT_CARD_ACCOUNT; 
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->bugsnag->notifyException($e);
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                422,
                $quote
            );

            return false;
        }
        
        return $discounts;
    }
    
    protected function removeDiscount($code, $discounts, $parentQuote, $immutableQuote, $websiteId, $storeId)
    {
        try{
            if(array_key_exists($code, $discounts)){
                if ($discounts[$code] == 'internal_coupon') {
                    $this->setCouponCode($parentQuote, '');
                    $this->setCouponCode($immutableQuote, '');
                    
                    $address = $immutableQuote->isVirtual() ?
                        $immutableQuote->getBillingAddress() :
                        $immutableQuote->getShippingAddress();
                    $this->totalsCollector->collectAddressTotals($immutableQuote, $address);
                } else if ($discounts[$code] == DiscountHelper::AMASTY_GIFTCARD) {
                    $giftCard = $this->discountHelper->loadAmastyGiftCard($code, $websiteId);                
                    $this->discountHelper->removeAmastyGiftCard($giftCard->getCodeId(), $parentQuote);
                    // Reset and apply Amasty Gift Cards to the immutable quote
                    $this->discountHelper->cloneAmastyGiftCards($parentQuote->getId(), $immutableQuote->getId());
                } else if ($discounts[$code] == DiscountHelper::MAGEPLAZA_GIFTCARD) {
                    $giftCard = $this->discountHelper->loadMageplazaGiftCard($code, $storeId);
                    $this->discountHelper->removeMageplazaGiftCard($giftCard->getId(), $immutableQuote);
                    $this->discountHelper->removeMageplazaGiftCard($giftCard->getId(), $parentQuote);
                } else if ($discounts[$code] == DiscountHelper::UNIRGY_GIFT_CERT) {
                
                } else if ($discounts[$code] == DiscountHelper::GIFT_CARD_ACCOUNT) {
                    $giftCard = $this->loadGiftCardData($couponCode, $websiteId);
                    $giftCard->removeFromCart(true, $immutableQuote);
                    $giftCard->removeFromCart(true, $parentQuote);
                }
            }    
        } catch (\Exception $e) {
            $this->bugsnag->notifyException($e);
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                422,
                $immutableQuote
            );

            return false;
        }
        
        return true;
    }
}
