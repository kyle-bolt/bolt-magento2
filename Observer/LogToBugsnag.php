<?php

namespace Bolt\Boltpay\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Bolt\Boltpay\Helper\Bugsnag;

/**
 *  LogToBugsnag
 */
class LogToBugsnag implements ObserverInterface
{
    /**
     * @var Bugsnag
     */
    protected $bugsnag;

    /**
     * LogToBugsnag constructor.
     * @param Bugsnag $bugsnag
     */
    public function __construct(
        Bugsnag $bugsnag
    )
    {
        $this->bugsnag = $bugsnag;
    }

    /**
     * @param EventObserver $observer
     *
     * @return void
     */
    public function execute(EventObserver $observer)
    {
        try{
            $event = $observer->getEvent();
            $order = $event->getOrder();
            if ($order->getPayment() && $order->getPayment()->getMethod() === \Bolt\Boltpay\Model\Payment::METHOD_CODE) {
                $orderIncrementId = $order->getIncrementId();
                $customerEmail = $order->getCustomerEmail();
                $this->bugsnag->notifyError('Customer goes to the success page successfully',"Order Increment Id: $orderIncrementId, Customer: $customerEmail");
            }
        }catch (\Exception $e){
            $this->bugsnag->notifyException($e);
        }
    }
}