<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Gateway\Request;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Shubo\TbcPayment\Api\Data\SplitPaymentDataInterface;
use Shubo\TbcPayment\Gateway\Config\Config;
use Shubo\TbcPayment\Gateway\Helper\SubjectReader;

/**
 * Adds split payment data to the request when enabled.
 *
 * Dispatches an event to allow other modules to provide split payment receivers.
 * This is disabled by default and requires explicit configuration.
 */
class SplitDataBuilder implements BuilderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SubjectReader $subjectReader,
        private readonly EventManagerInterface $eventManager,
    ) {
    }

    /**
     * @param array<string, mixed> $buildSubject
     * @return array<string, mixed>
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $storeId = (int) $order->getStoreId();

        if (!$this->config->isSplitPaymentsEnabled($storeId)) {
            return [];
        }

        $transport = new \Magento\Framework\DataObject([
            'receivers' => [],
            'order' => $order,
        ]);

        $this->eventManager->dispatch('shubo_tbc_payment_split_data', [
            'transport' => $transport,
            'payment' => $paymentDO,
        ]);

        /** @var SplitPaymentDataInterface[] $receivers */
        $receivers = $transport->getData('receivers');

        if (empty($receivers)) {
            return [];
        }

        $receiversData = [];
        foreach ($receivers as $receiver) {
            $receiversData[] = [
                'merchant_id' => $receiver->getMerchantId(),
                'amount' => $receiver->getAmount(),
                'currency' => $receiver->getCurrency(),
                'description' => $receiver->getDescription(),
            ];
        }

        return [
            'receivers' => $receiversData,
        ];
    }
}
