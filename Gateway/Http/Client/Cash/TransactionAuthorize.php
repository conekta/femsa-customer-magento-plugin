<?php

namespace Femsa\Payments\Gateway\Http\Client\Cash;

use Femsa\Payments\Api\FemsaApiClient;
use Femsa\Payments\Helper\Data as FemsaHelper;
use Femsa\Payments\Logger\Logger as FemsaLogger;
use Femsa\Payments\Api\Data\FemsaSalesOrderInterface;
use Femsa\Payments\Model\FemsaSalesOrderFactory;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;

class TransactionAuthorize implements ClientInterface
{
    const SUCCESS = 1;
    const FAILURE = 0;

    /**
     * @var array
     */
    private array $results = [
        self::SUCCESS,
        self::FAILURE
    ];

    /**
     * @var Logger
     */
    private Logger $logger;

    protected FemsaHelper $_femsaHelper;

    private FemsaLogger $_femsaLogger;

    protected FemsaSalesOrderFactory $femsaSalesOrderFactory;

    /**
     * @var FemsaApiClient
     */
    private FemsaApiClient $femsaApiClient;

    /**
     * @param Logger $logger
     * @param FemsaHelper $femsaHelper
     * @param FemsaLogger $femsaLogger
     * @param FemsaApiClient $femsaApiClient
     * @param FemsaSalesOrderFactory $femsaSalesOrderFactory
     */
    public function __construct(
        Logger                   $logger,
        FemsaHelper              $femsaHelper,
        FemsaLogger              $femsaLogger,
        FemsaApiClient           $femsaApiClient,
        FemsaSalesOrderFactory $femsaSalesOrderFactory
    )
    {
        $this->_femsaHelper = $femsaHelper;
        $this->_femsaLogger = $femsaLogger;
        $this->femsaApiClient = $femsaApiClient;
        $this->_femsaLogger->info('HTTP Client Cash TransactionAuthorize :: __construct');
        $this->logger = $logger;
        $this->femsaSalesOrderFactory = $femsaSalesOrderFactory;
    }

    /**
     * Places request to gateway. Returns result as ENV array
     *
     * @param TransferInterface $transferObject
     * @return array
     * @throws \Exception
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        $this->_femsaLogger->info('HTTP Client Cash TransactionAuthorize :: placeRequest');
        $request = $transferObject->getBody();

        $orderParams['currency'] = $request['CURRENCY'];
        $orderParams['line_items'] = $request['line_items'];
        $orderParams['tax_lines'] = $request['tax_lines'];
        $orderParams['customer_info'] = $request['customer_info'];
        $orderParams['discount_lines'] = $request['discount_lines'];
        if (!empty($request['shipping_lines'])) {
            $orderParams['shipping_lines'] = $request['shipping_lines'];
        }
        if (!empty($request['shipping_contact'])) {
            $orderParams['shipping_contact'] = $request['shipping_contact'];
        }
        $orderParams['metadata'] = $request['metadata'];
        $chargeParams = $request['payment_method_details'];

        $txn_id = '';
        $ord_id = '';
        $error_code = '';

        try {
            $femsaOrder = $this->femsaApiClient->createOrder($orderParams);
            $charge = $this->femsaApiClient->createOrderCharge($femsaOrder->getId(), $chargeParams);

            if ($charge->getId() && $femsaOrder->getId()) {
                $result_code = 1;
                $txn_id = $charge->getId();
                $ord_id = $femsaOrder->getId();

                $this->femsaSalesOrderFactory
                    ->create()
                    ->setData([
                        FemsaSalesOrderInterface::FEMSA_ORDER_ID => $ord_id,
                        FemsaSalesOrderInterface::INCREMENT_ORDER_ID => $orderParams['metadata']['order_id']
                    ])
                    ->save();
            } else {
                $result_code = 666;
            }
        } catch (\Exception $e) {
            $this->_femsaLogger->error(__('[Femsa]: Payment capturing error.'));
            $this->logger->debug(
                [
                    'request' => $request,
                    'response' => $e->getMessage()
                ]
            );
            $this->_femsaLogger->info(
                'HTTP Client Cash TransactionAuthorize :: placeRequest: Payment authorize error ' . $e->getMessage()
            );
            throw new \Exception(__($e->getMessage()));
        }

        $response = $this->generateResponseForCode(
            $result_code,
            $txn_id,
            $ord_id
        );

        $response['offline_info'] = [
            "type" => $charge->getPaymentMethod()->getType(),
            'barcode_url' => $charge->getPaymentMethod()->getBarcodeUrl(),
            "data" => [
                "reference" => $charge->getPaymentMethod()->getReference(),
                "expires_at" => $charge->getPaymentMethod()->getExpiresAt()
            ]
        ];

        $response['error_code'] = $error_code;

        $this->logger->debug(
            [
                'request' => $request,
                'response' => $response
            ]
        );

        $this->_femsaLogger->info(
            'HTTP Client Cash TransactionAuthorize :: placeRequest',
            [
                'request' => $request,
                'response' => $response
            ]
        );

        $response['payment_method_details'] = $request['payment_method_details'];

        return $response;
    }

    protected function generateResponseForCode($resultCode, $txn_id, $ord_id): array
    {
        $this->_femsaLogger->info('HTTP Client Cash TransactionAuthorize :: generateResponseForCode');

        return array_merge(
            [
                'RESULT_CODE' => $resultCode,
                'TXN_ID' => $txn_id,
                'ORD_ID' => $ord_id
            ]
        );
    }
}