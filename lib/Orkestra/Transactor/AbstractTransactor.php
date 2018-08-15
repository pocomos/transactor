<?php

/*
 * This file is part of the Orkestra Transactor package.
 *
 * Copyright (c) Orkestra Community
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace Orkestra\Transactor;

use Orkestra\Transactor\Entity\AbstractAccount;
use Orkestra\Transactor\Entity\Account\BankAccount;
use Orkestra\Transactor\Entity\Account\CardAccount;
use Orkestra\Transactor\Entity\Result\ResultStatus;
use Orkestra\Transactor\Entity\Result;
use Orkestra\Transactor\Entity\Transaction;
use Orkestra\Transactor\Exception\TransactorException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

/**
 * Base class for any Transactor
 */
abstract class AbstractTransactor implements TransactorInterface
{
    /**
     * @var array $supportedNetworks An array of NetworkType constants
     */
    protected static $supportedNetworks = array();

    /**
     * @var array $supportedTypes An array of TransactionType constants
     */
    protected static $supportedTypes = array();

    /**
     * @var \Symfony\Component\OptionsResolver\OptionsResolverInterface
     */
    private $resolver;

    /**
     * Transacts the given transaction
     *
     *
     * @param \Orkestra\Transactor\Entity\Transaction $transaction
     * @param array                                   $options
     *
     * @throws \Orkestra\Transactor\Exception\TransactorException
     * @return \Orkestra\Transactor\Entity\Result
     */
    public function transact(Transaction $transaction, array $options = array())
    {
        if ($transaction->isTransacted()) {
            throw TransactorException::transactionAlreadyProcessed();
        } elseif (!$this->supportsType($transaction->getType())) {
            throw TransactorException::unsupportedTransactionType($transaction->getType());
        } elseif (!$this->supportsNetwork($transaction->getNetwork())) {
            throw TransactorException::unsupportedTransactionNetwork($transaction->getNetwork());
        }

        $result = $transaction->getResult();
        $result->setTransactor($this);


        try {
            $options = $this->getResolver()->resolve($options);
            $account = $transaction->getAccount();
            // To Tokenize or Not To Tokenize, that is the question
            $toTokenize = (!$account->getAccountToken() && $account->isTokenizeable());

            if($toTokenize){
                $options['tokenize'] = true;
            }

            $this->doTransact($transaction, $options);

            if($toTokenize){
                if($result->getStatus() == ResultStatus::APPROVED){
                    $data = $result->getData('response');
                    if(isset($data['customer_vault_id']) && $data['response'] = 1){
                        $account->setAccountToken($data['customer_vault_id']);
                        $account->setDateTokenized(new \DateTime());
                    }
                }
            }
        } catch (\Exception $e) {
            $result->setStatus(new ResultStatus(ResultStatus::ERROR));
            $result->setMessage('An internal error occurred while processing the transaction.');
            $result->setData('message', $e->getMessage());
            $result->setData('trace', $e->getTraceAsString());
        }

        return $this->filterResult($result);
    }

    /**
     * @param AbstractAccount $account
     * @param array $options
     * @return array
     * @throws \Exception
     */
    public function tokenizeAccount(AbstractAccount $account,array $options = []){
        $options = $this->getResolver()->resolve($options);

        $tokenizingTransaction = new Transaction();
        $tokenizingTransaction->setAccount($account);
        $tokenizingTransaction->setAmount(0);
        $tokenizingTransaction->setCredentials($account->getCredentials());
        $tokenizingTransaction->setType(new Transaction\TransactionType(Transaction\TransactionType::VALIDATE));

        if($account instanceof  BankAccount){
            $networkType = new Transaction\NetworkType(Transaction\NetworkType::ACH);
        }elseif($account instanceof CardAccount){
            $networkType = new Transaction\NetworkType(Transaction\NetworkType::CARD);
        } else {
            throw new \Exception('Account Type is missing');
        }

        if(!$account->isTokenizeable()){
            throw new \Exception('This type of account is not tokenizeable');
        }

        $tokenizingTransaction->setNetwork($networkType);
        $tokenizingTransaction->setStatus(new Result\ResultStatus(Result\ResultStatus::PENDING));

        $options['tokenize']=true;

        $result = $this->doTransact($tokenizingTransaction,$options);
//        $BadJooJoo = [Result\ResultStatus::DECLINED,Result\ResultStatus::ERROR];
//        if(in_array($result->getStatus(),$BadJooJoo)){
//            return $result;
//        }
        $data = $result->getData('response');
        return $data;

//        $tokenizingTransaction->getAccount()->setAccountToken($data['customer_vault_id']);
//        $tokenizingTransaction->getAccount()->setDateTokenized(new \DateTime());
//
//        return $tokenizingTransaction->getAccount();

    }

    /**
     * Transacts the given transaction
     *
     * @param \Orkestra\Transactor\Entity\Transaction $transaction
     * @param array                                   $options
     *
     * @return \Orkestra\Transactor\Entity\Result
     */
    abstract protected function doTransact(Transaction $transaction, array $options = array());

    /**
     * Configures the transactors OptionsResolver
     *
     * Override this method to tell the resolver what options are available.
     * This resolver will be used to validate the options passed to the
     * transact method.
     *
     * @see transact
     *
     * @param \Symfony\Component\OptionsResolver\OptionsResolverInterface $resolver
     *
     * @return void
     */
    protected function configureResolver(OptionsResolverInterface $resolver)
    {
    }

    /**
     * Filter the given result
     * 
     * @param Result $result
     *
     * @return Result
     */
    protected function filterResult(Result $result)
    {
        return $result;
    }

    /**
     * @return \Symfony\Component\OptionsResolver\OptionsResolverInterface
     */
    protected function getResolver()
    {
        if (null === $this->resolver) {
            $this->resolver = new OptionsResolver();
            $this->configureResolver($this->resolver);
        }

        return $this->resolver;
    }

    /**
     * Returns true if this Transactor supports a given Transaction type
     *
     * @param \Orkestra\Transactor\Entity\Transaction\TransactionType|null $type
     *
     * @return boolean True if supported
     */
    public function supportsType(Transaction\TransactionType $type = null)
    {
        return in_array((null === $type ? null : $type->getValue()), static::$supportedTypes);
    }

    /**
     * Returns true if this Transactor supports a given Network type
     *
     * @param \Orkestra\Transactor\Entity\Transaction\NetworkType|null $network
     *
     * @return boolean True if supported
     */
    public function supportsNetwork(Transaction\NetworkType $network = null)
    {
        return in_array((null === $network ? null : $network->getValue()), static::$supportedNetworks);
    }

    /**
     * To String
     *
     * @return string
     */
    public function __toString()
    {
        return sprintf('%s (%s)', $this->getName(), $this->getType());
    }

    /**
     * Creates a new, empty Credentials
     *
     * @return Entity\Credentials
     */
    public function createCredentials()
    {
        $credentials = new Entity\Credentials();
        $credentials->setTransactor($this);

        return $credentials;
    }
}
