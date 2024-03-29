<?php

namespace Femsa\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Femsa\Payments\Model\Config;
use Magento\Framework\Event\Observer;
use Magento\Framework\Validator\Exception;

/**
 * Class CreateWebhook
 */
class Webhook implements ObserverInterface
{
    /**
     * @var Config
     */
    protected Config $config;
    /**
     * @var ManagerInterface
     */
    protected ManagerInterface $messageManager;
    /**
     * @param Config $config
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        Config $config,
        ManagerInterface $messageManager
    ) {
        $this->config = $config;
        $this->messageManager = $messageManager;
    }

    /**
     * Create Webhook
     *
     * @param Observer $observer
     * @throws Exception
     * @throws NoSuchEntityException
     */
    public function execute(Observer $observer)
    {
        $this->config->createWebhook();
    }
}
