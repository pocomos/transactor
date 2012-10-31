<?php

namespace Orkestra\Transactor\Transactor\Generic;

use Orkestra\Transactor\AbstractTransactor;
use Orkestra\Transactor\Exception\ValidationException;
use Orkestra\Transactor\Entity\Transaction;
use Orkestra\Transactor\Entity\Result;

/**
 * Handles in person cash or check transactions
 */
class GenericTransactor extends AbstractTransactor
{
    /**
     * @var array
     */
    protected static $_supportedNetworks = array(
        Transaction\NetworkType::CASH,
        Transaction\NetworkType::CHECK
    );

    /**
     * @var array
     */
    protected static $_supportedTypes = array(
        Transaction\TransactionType::SALE,
        Transaction\TransactionType::CREDIT,
        Transaction\TransactionType::REFUND,
    );

    /**
     * Transacts the given transaction
     *
     * @param \Orkestra\Transactor\Entity\Transaction $transaction
     * @param array $options
     *
     * @return \Orkestra\Transactor\Entity\Result
     */
    protected function _doTransact(Transaction $transaction, $options = array())
    {
        $this->_validateTransaction($transaction);

        $result = $transaction->getResult();
        $result->setTransactor($this);

        $result->setStatus(new Result\ResultStatus(Result\ResultStatus::APPROVED));

        return $result;
    }

    /**
     * Validates the given transaction
     *
     * @param \Orkestra\Transactor\Entity\Transaction $transaction
     *
     * @throws \Orkestra\Transactor\Exception\ValidationException
     */
    protected function _validateTransaction(Transaction $transaction)
    {
        if (!$transaction->getParent() && in_array($transaction->getType()->getValue(), array(
            Transaction\TransactionType::CAPTURE,
            Transaction\TransactionType::REFUND
        ))) {
            throw ValidationException::parentTransactionRequired();
        }
    }

    /**
     * Returns the internally used type of this Transactor
     *
     * @return string
     */
    function getType()
    {
        return 'orkestra.generic';
    }

    /**
     * Returns the name of this Transactor
     *
     * @return string
     */
    public function getName()
    {
        return 'Generic Transactor';
    }
}