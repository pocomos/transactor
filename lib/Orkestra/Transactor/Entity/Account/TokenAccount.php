<?php

/*
 * This file is part of the Orkestra Transactor package.
 *
 * Copyright (c) Orkestra Community
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace Orkestra\Transactor\Entity\Account;

use Doctrine\ORM\Mapping as ORM;

use Orkestra\Transactor\Entity\AbstractAccount;
use Orkestra\Transactor\Type\Month;
use Orkestra\Transactor\Type\Year;

/**
 * Represents a single credit card account
 *
 * @ORM\Entity
 */
class TokenAccount extends AbstractAccount
{
    /**
     * @var \DateTime $dateModified
     *
     * @ORM\Column(name="date_tokenized", type="datetime", nullable=true)
     */
    protected $dateTokenized;

    /**
     * @return \DateTime
     */
    public function getDateTokenized()
    {
        return $this->dateTokenized;
    }

    /**
     * @param \DateTime $dateTokenized
     */
    public function setDateTokenized(\DateTime $dateTokenized)
    {
        $this->dateTokenized = $dateTokenized;
    }

    /**
     * Return a printable type name
     *
     * @return string
     */
    public function getType()
    {
        return 'Token Account';
    }
}
