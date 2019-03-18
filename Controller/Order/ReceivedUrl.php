<?php
/**
 * ReceivedUrl
 *
 * @copyright Copyright © 2019 Staempfli AG. All rights reserved.
 * @author    juan.alonso@staempfli.com
 */

namespace Bolt\Boltpay\Controller\Order;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Bolt\Boltpay\Helper\Log as LogHelper;
use Bolt\Boltpay\Helper\Config as ConfigHelper;
use Bolt\Boltpay\Helper\Bugsnag;
use Magento\Framework\UrlInterface;

class ReceivedUrl extends Action
{
    private $logHelper;
    private $resultJsonFactory;
    private $configHelper;
    private $bugsnag;
    private $url;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        configHelper $configHelper,
        Bugsnag $bugsnag,
        LogHelper $logHelper,
        UrlInterface $url
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->configHelper = $configHelper;
        $this->bugsnag = $bugsnag;
        $this->logHelper = $logHelper;
        $this->url = $url;
    }

    public function execute()
    {
        $boltSignature = $this->getRequest()->getParam('bolt_signature');
        $boltPayload = $this->getRequest()->getParam('bolt_payload');

        $signature = base64_decode($boltSignature);

        $magentoSavedSignature = $this->configHelper->getSigningSecret();

        $hashBoltPayloadWithKey = hash_hmac('sha256', $boltPayload, $magentoSavedSignature, true);
        $hash = base64_encode($hashBoltPayloadWithKey);

        $this->logHelper->addInfoLog('# Is Equal: ' . (($signature === $hash) ? " yes" : "no"));

        if ($signature === $hash) {
            // it is BOLT!
            $this->messageManager->addErrorMessage(__('Something went wrong. Please contact the seller.'));
            $this->_redirect('/checkout/onepage/success/');
        } else {
            // Potentially it is attack.
            $this->messageManager->addErrorMessage(__('Something went wrong. Please contact the seller.'));
            $this->logHelper->addInfoLog('bolt_signature and Magento signature is not equal');
            $this->_redirect('/');
        }
    }
}