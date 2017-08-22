<?php

/*
 * This file is part of the Orkestra Transactor package.
 *
 * Copyright (c) Orkestra Community
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace Orkestra\Transactor\Tokenization\NetworkMerchants;

use Doctrine\ORM\EntityManager;
use Orkestra\Common\Exception\Exception;
use Orkestra\Transactor\Entity\AbstractAccount;
use Orkestra\Transactor\Entity\Account\SwipedCardAccount;
use Orkestra\Transactor\AbstractTransactor;
use Orkestra\Transactor\Entity\Credentials;
use Orkestra\Transactor\Entity\Transaction;
use Orkestra\Transactor\Entity\Result;
use Orkestra\Transactor\Entity\Account\CardAccount;
use Orkestra\Transactor\Exception\ValidationException;
use Guzzle\Http\Client;
use Guzzle\Http\Exception\BadResponseException;
use Orkestra\Transactor\Transactor\NetworkMerchants\AchTransactor;
use Orkestra\Transactor\Transactor\NetworkMerchants\CardTransactor;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

/**
 * Credit card transactor for the Network Merchants payment processing gateway
 */
class Tokenizer extends AbstractTransactor
{
    /**
     * @var array
     */
    protected static $supportedNetworks = array(
        Transaction\NetworkType::CARD,
        Transaction\NetworkType::ACH,
    );

    /**
     * @var array
     */
    protected static $supportedTypes = array(
        Transaction\TransactionType::VALIDATE
    );

    /**
     * @var \Guzzle\Http\Client
     */
    protected $client;

    /**
     * @var CardTransactor
     */
    protected $cardTransactor;

    /**
     * @var AchTransactor
     */
    protected $achTransactor;

    /**
     * Constructor
     *
     * @param \Guzzle\Http\Client $client
     * @param CardTransactor $cardTransactor
     * @param AchTransactor $achTransactor
     */
    public function __construct(Client $client = null, CardTransactor $cardTransactor, AchTransactor $achTransactor)
    {
        $this->client = $client;
        $this->cardTransactor = $cardTransactor;
        $this->achTransactor = $achTransactor;
    }


    /**
     * @param AbstractAccount $account
     * @param array $options
     * @return AbstractAccount $account
     */
    public function tokenizeAccount(AbstractAccount $account,array $options = [])
    {
        $accountType = $account->getType();
        $options = $this->getResolver()->resolve($options);
        if($accountType === "Bank Account"){
            $networkType = Transaction\NetworkType::ACH;
        } elseif($accountType === "Credit Card"){
            $networkType = Transaction\NetworkType::CARD;
        } else {
            throw new Exception('Account Type is missing');
        }


        $tokenizingTransaction = new Transaction();
        $tokenizingTransaction->setAccount($account);
        $tokenizingTransaction->setAmount(0);
        $tokenizingTransaction->setCredentials($account->getCredentials());
        $tokenizingTransaction->setType(new Transaction\TransactionType(Transaction\TransactionType::VALIDATE));
        $tokenizingTransaction->setNetwork($networkType);
        $tokenizingTransaction->setStatus(Result\ResultStatus::PENDING);

        $options['tokenize'] = true;

        $result = $this->doTransact($tokenizingTransaction, $options);
        $BadJooJoo = [Result\ResultStatus::DECLINED, Result\ResultStatus::ERROR];
        if (in_array($result->getStatus(), $BadJooJoo)) {
            return $result;
        }
        $data = $result->getData('response');
        $account->setAccountToken($data['customer_vault_id']);
        $account->setDateTokenized(new \DateTime());

        return $account;
    }

    /**
     * Transacts the given transaction
     *
     * @param \Orkestra\Transactor\Entity\Transaction $transaction
     * @param array                                   $options
     *
     * @return \Orkestra\Transactor\Entity\Result
     */
    protected function doTransact(Transaction $transaction, array $options = array())
    {
        $this->validateTransaction($transaction);
        $accountType = $transaction->getAccount()->getType();
        if($accountType === "Bank Account"){
            $params = $this->achTransactor->buildParams($transaction, $options);
        } elseif($accountType === "Card Account"){
            $params = $this->cardTransactor->buildParams($transaction, $options);
        } else {
            throw new Exception('Account of undefined type. Please provide a bank account or a card account');
        }
        $result = $transaction->getResult();
        $result->setTransactor($this);

        $postUrl = $options['post_url'];
        $client = $this->getClient();

        $request = $client->post($postUrl)
            ->addPostFields($params);

        try {
            $response = $request->send();
            $data = array();
            parse_str($response->getBody(true), $data);
        } catch (BadResponseException $e) {
            $data = array(
                'response' => '3',
                'message' => $e->getMessage()
            );
        }

        if (empty($data['response']) || '1' != $data['response']) {
            $result->setStatus(new Result\ResultStatus((!empty($data['response']) && '2' == $data['response']) ? Result\ResultStatus::DECLINED : Result\ResultStatus::ERROR));
            $result->setMessage(empty($data['responsetext']) ? 'An error occurred while processing the payment. Please try again.' : $data['responsetext']);

            if (!empty($data['transactionid'])) {
                $result->setExternalId($data['transactionid']);
            }
        } else {
            $result->setStatus(new Result\ResultStatus(Result\ResultStatus::APPROVED));
            $result->setExternalId($data['transactionid']);
        }

        $result->setData('request', $params);
        $result->setData('response', $data);

        return $result;
    }

    /**
     * Validates the given transaction
     *
     * @param \Orkestra\Transactor\Entity\Transaction $transaction
     *
     * @throws \Orkestra\Transactor\Exception\ValidationException
     */
    protected function validateTransaction(Transaction $transaction)
    {
        $credentials = $transaction->getCredentials();

        if (!$credentials) {
            throw ValidationException::missingCredentials();
        } elseif (null === $credentials->getCredential('username') || null === $credentials->getCredential('password')) {
            throw ValidationException::missingRequiredParameter('username or password');
        }

        $account = $transaction->getAccount();

        if (!$account) {
            throw ValidationException::missingAccountInformation();
        }

        if (!($account instanceof CardAccount )
        ) {
            throw ValidationException::invalidAccountType($account);
        }

        if (!$account instanceof SwipedCardAccount) {
            if (null === $account->getAccountNumber()) {
                throw ValidationException::missingRequiredParameter('account number');
            } elseif (null === $account->getExpMonth() || null === $account->getExpYear()) {
                throw ValidationException::missingRequiredParameter('card expiration');
            }
        }
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
        $request = $result->getData('request') ?: array();
        foreach (array('ccnumber', 'cvv', 'track_1', 'track_2', 'track_3') as $key) {
            if (array_key_exists($key, $request)) {
                $request[$key] = '[filtered]';
            }
        }

        $result->setData('request', $request);

        return $result;
    }

    /**
     * @param \Symfony\Component\OptionsResolver\OptionsResolverInterface $resolver
     */
    protected function configureResolver(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'tokenize' => false,
            'enable_avs' => false,
            'enable_cvv' => false,
            'post_url'   => 'https://secure.bottomlinegateway.com/api/transact.php',
        ));
    }

    /**
     * @return \Guzzle\Http\Client
     */
    protected function getClient()
    {
        if (null === $this->client) {
            $this->client = new Client();
        }

        return $this->client;
    }

    /**
     * Creates a new, empty Credentials entity
     *
     * @return \Orkestra\Transactor\Entity\Credentials
     */
    public function createCredentials()
    {
        $credentials = new Credentials();
        $credentials->setTransactor($this);
        $credentials->setCredentials(array(
            'username' => null,
            'password' => null,
        ));

        return $credentials;
    }

    /**
     * Returns the internally used type of this Transactor
     *
     * @return string
     */
    public function getType()
    {
        return 'orkestra.network_merchants.card';
    }

    /**
     * Returns the name of this Transactor
     *
     * @return string
     */
    public function getName()
    {
        return 'Network Merchants Credit Card Gateway';
    }
}
