<?php

namespace Femsa\Payments\Model;

use Magento\Framework\Session\Config\ConfigInterface;
use Magento\Framework\Session\SaveHandlerInterface;
use Magento\Framework\Session\SessionManager;
use Magento\Framework\Session\SessionStartChecker;
use Magento\Framework\Session\SidResolverInterface;
use Magento\Framework\Session\StorageInterface;
use Magento\Framework\Session\ValidatorInterface;

class Session extends SessionManager
{
    /**
     * @var StorageInterface
     */
    protected $storage;

    /**
     * Session constructor.
     *
     * @param StorageInterface $storage
     */
    public function __construct(StorageInterface $storage) {
        $this->storage = $storage;
    }

    /**
     * Set Promotion Code
     *
     * @param string|null $url
     * @return $this
     */
    public function setFemsaCheckoutId(?string $url): Session
    {
        $this->storage->setData('femsa_checkout_id', $url);
        return $this;
    }

    /**
     * Retrieve promotion code from current session
     *
     * @return string|null
     */
    public function getFemsaCheckoutId(): ?string
    {
        if ($this->storage->getData('femsa_checkout_id')) {
            return $this->storage->getData('femsa_checkout_id');
        }
        return null;
    }

    public function getFemsaCustomerId(){
        if ($this->storage->getData('femsa_customer_id')) {
            return $this->storage->getData('femsa_customer_id');
        }
        return null;
    }
}
