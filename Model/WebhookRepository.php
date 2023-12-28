<?php

namespace Femsa\Payments\Model;

use Femsa\Payments\Logger\Logger as FemsaLogger;
use Femsa\Payments\Api\Data\FemsaSalesOrderInterface;
use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;

class WebhookRepository
{
    /**
     * @var OrderInterface
     */
    protected OrderInterface $orderInterface;
    /**
     * @var InvoiceService
     */
    protected InvoiceService $invoiceService;
    /**
     * @var InvoiceSender
     */
    protected InvoiceSender $invoiceSender;
    /**
     * @var Transaction
     */
    protected Transaction $transaction;
    /**
     * @var FemsaLogger
     */
    private FemsaLogger $_logger;
    /**
     * @var FemsaSalesOrderInterface
     */
    private FemsaSalesOrderInterface $femsaOrderSalesInterface;

    /**
     * @param OrderInterface $orderInterface
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param Transaction $transaction
     * @param FemsaLogger $logger
     * @param FemsaSalesOrderInterface $femsaOrderSalesInterface
     */
    public function __construct(
        OrderInterface           $orderInterface,
        InvoiceService           $invoiceService,
        InvoiceSender            $invoiceSender,
        Transaction              $transaction,
        FemsaLogger              $logger,
        FemsaSalesOrderInterface $femsaOrderSalesInterface
    ) {
        $this->orderInterface = $orderInterface;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->transaction = $transaction;
        $this->_logger = $logger;
        $this->femsaOrderSalesInterface = $femsaOrderSalesInterface;
    }

    /**
     * Find store order in body. If keys['data']['object']['metadata']['order_id'] does not exist, throws an Exception
     *
     * @param array $body
     * @return Order
     * @throws LocalizedException
     */
    public function findByMetadataOrderId(array $body): Order
    {
        if (!isset($body['data']['object']) ||
            !isset($body['data']['object']['id'])
        ) {
            throw new LocalizedException(__('Missing order information'));
        }
        $femsaOrderId = $body['data']['object']['id'];
        
        $this->_logger->info('WebhookRepository :: findByMetadataOrderId started', [
            'order_id' => $femsaOrderId
        ]);

        $femsaSalesOrder = $this->femsaOrderSalesInterface->loadByFemsaOrderId($femsaOrderId);

        return $this->orderInterface->loadByIncrementId($femsaSalesOrder->getIncrementOrderId());
    }

    /**
     * Finds order by metadata id in $body If state == Pending, set as CANCELED If order not exists, throws exception
     *
     * @param array $body
     * @return void
     * @throws LocalizedException
     * @throws Exception
     */
    public function expireOrder(array $body)
    {
        $this->_logger->info('WebhookRepository :: expireOrder started');

        $order = $this->findByMetadataOrderId($body);

        if (!$order->getId()) {
            throw new LocalizedException(__('We could not locate the order in the store'));
        }

        //Only update order status if order is Pending
        if ($order->getState() === Order::STATE_PENDING_PAYMENT ||
            $order->getState() === Order::STATE_PAYMENT_REVIEW
        ) {
            if ($order->canCancel()) {
                $order->cancel();
                $order->setState(Order::STATE_CANCELED);
                $order->setStatus(Order::STATE_CANCELED);
                $order->addCommentToStatusHistory("Order Expired")
                    ->setIsCustomerNotified(true);

                $order->save();

                $this->_logger->info('La orden con ID $order_id ha sido cancelada exitosamente', ["id"=>$order->getId()]);

            } else {
                $this->_logger->info("No se puede cancelar la orden con ID  en su estado actual.",["id"=>$order->getId()]);
            }
        }
    }

    /**
     * Pay Order
     *
     * @param mixed $body
     * @return void
     * @throws LocalizedException
     * @throws Exception
     */
    public function payOrder($body)
    {

        $order = $this->findByMetadataOrderId($body);
        
        $charge = $body['data']['object'];
        if (!isset($charge['payment_status']) || $charge['payment_status'] !== "paid") {
            throw new LocalizedException(__('Missing order information'));
        }
        
        if (!$order->getId()) {
            $message = 'The order does not exists';
            $this->_logger->error(
                'WebhookRepository :: execute - ' . $message
            );
            throw new LocalizedException(__($message));
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);

        $order->addCommentToStatusHistory("Payment received successfully")
            ->setIsCustomerNotified(true);

        $order->save();
        $this->_logger->info('WebhookRepository :: execute - Order status updated');

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->register();
        $invoice->save();
        $transactionSave = $this->transaction->addObject(
            $invoice
        )->addObject(
            $invoice->getOrder()
        );
        $transactionSave->save();

        $this->_logger->info('WebhookRepository :: execute - The invoice to be created');

        try {
            $this->invoiceSender->send($invoice);
            $order->addCommentToStatusHistory(
                __('Notified customer about invoice creation #%1.', $invoice->getId())
            )
                ->setIsCustomerNotified(true)
                ->save();
            $this->_logger->info(
                'WebhookRepository :: execute - Notified customer about invoice creation'
            );
        } catch (Exception $e) {
            $this->_logger->error($e->getMessage());
        }
    }
}