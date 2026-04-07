<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

class Receivers extends AbstractFieldArray
{
    /**
     * @var AmountTypeColumn|null
     */
    private ?AmountTypeColumn $amountTypeRenderer = null;

    /**
     * Configure columns for the dynamic rows table.
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn('merchant_id', [
            'label' => __('Merchant ID'),
            'class' => 'required-entry validate-digits',
        ]);
        $this->addColumn('amount_type', [
            'label' => __('Amount Type'),
            'renderer' => $this->getAmountTypeRenderer(),
        ]);
        $this->addColumn('amount', [
            'label' => __('Amount'),
            'class' => 'required-entry validate-number validate-greater-than-zero',
        ]);
        $this->addColumn('description', [
            'label' => __('Description'),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Receiver');
    }

    /**
     * Set selected option for amount type dropdown in each row.
     *
     * @param DataObject $row Row data object
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $amountType = $row->getData('amount_type');
        if ($amountType !== null) {
            $key = 'option_' . $this->getAmountTypeRenderer()->calcOptionHash($amountType);
            $options[$key] = 'selected="selected"';
        }
        $row->setData('option_extra_attrs', $options);
    }

    /**
     * Get or create the amount type dropdown renderer.
     *
     * @return AmountTypeColumn
     * @throws LocalizedException
     */
    private function getAmountTypeRenderer(): AmountTypeColumn
    {
        if ($this->amountTypeRenderer === null) {
            /** @var AmountTypeColumn $block */
            $block = $this->getLayout()->createBlock(
                AmountTypeColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $this->amountTypeRenderer = $block;
        }
        return $this->amountTypeRenderer;
    }
}
