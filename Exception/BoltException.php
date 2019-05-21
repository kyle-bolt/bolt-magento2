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

namespace Bolt\Boltpay\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class BoltException extends LocalizedException
{
    /**
     * Overide LocalizedException constructor because in older Magento versions
     * it does not take the $code parameter into account, defaulting it to 0.
     * Bypass the parent and call grandparent's constructor
     *
     * @param \Magento\Framework\Phrase $phrase
     * @param \Exception $cause
     * @param int $code
     */
    public function __construct(Phrase $phrase, \Exception $cause = null, $code = 0)
    {
        $this->phrase = $phrase;
        \Exception::__construct($phrase->render(), intval($code), $cause);
    }
}
