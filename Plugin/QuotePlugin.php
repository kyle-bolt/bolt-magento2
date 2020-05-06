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
namespace Bolt\Boltpay\Plugin;
use Bolt\Boltpay\Helper\Bugsnag;

/**
 * Class QuotePlugin
 *
 * @package Bolt\Boltpay\Plugin
 */
class QuotePlugin
{
    /**
     * @var Bugsnag
     */
    private $bugsnag;

    /**
     * QuotePlugin constructor.
     * @param Bugsnag $bugsnag
     */
    public function __construct(
        Bugsnag $bugsnag
    )
    {
        $this->bugsnag = $bugsnag;
    }

    /**
     * Override Quote afterSave method.
     * Skip execution for inactive quotes, thus preventing dispatching the after save events.
     *
     * @param \Magento\Quote\Model\Quote $subject
     * @param callable $proceed
     * @return \Magento\Quote\Model\Quote
     */
    public function aroundAfterSave(\Magento\Quote\Model\Quote $subject, callable $proceed)
    {
        if ($subject->getIsActive()) {
            return $proceed();
        }
        return $subject;
    }

    /**
     * @param \Magento\Quote\Model\Quote $subject
     * @param $data
     * @return array
     */
    public function beforeSetIsActive(\Magento\Quote\Model\Quote $subject, $data)
    {
        if (
            $subject->getPayment()
            && $subject->getPayment()->getMethod() === \Bolt\Boltpay\Model\Payment::METHOD_CODE
            && $data
        ) {
            $quoteID = $subject->getId();
            $this->bugsnag->notifyError('Active Quote','Quote ID:' . $quoteID);
        }

        return [$data];
    }
}
