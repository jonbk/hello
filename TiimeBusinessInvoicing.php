<?php

namespace App\Entity\TiimeBusiness;

use App\Entity\BankTransaction;
use App\Entity\Invoicing\Invoice;
use App\Entity\WalletCompany;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(
 *     name="tiime_business_invoicing",
 *     schema="tiime_business"
 * )
 */
class TiimeBusinessInvoicing
{
    /**
     * @ORM\Column(name="id", type="bigint")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private ?int $id;

    /**
     * @ORM\ManyToOne(targetEntity=WalletCompany::class)
     * @ORM\JoinColumn(name="wallet_company_id", referencedColumnName="id", nullable=false)
     */
    private WalletCompany $walletCompany;

    /**
     * @ORM\OneToOne(targetEntity=Invoice::class)
     * @ORM\JoinColumn(name="invoice_id", referencedColumnName="id", onDelete="SET NULL")
     */
    private ?Invoice $invoice;

    /**
     * @ORM\Column(type="datetime", name="start_date")
     */
    private \DateTimeInterface $startDate;

    /**
     * @ORM\Column(type="datetime", name="end_date")
     */
    private \DateTimeInterface $endDate;

    /**
     * @ORM\OneToOne(targetEntity=BankTransaction::class)
     * @ORM\JoinColumn(name="tiime_transaction_id", referencedColumnName="id", onDelete="SET NULL")
     */
    private ?BankTransaction $tiimeTransaction;

    /**
     * @ORM\OneToOne(targetEntity=BankTransaction::class)
     * @ORM\JoinColumn(name="client_transaction_id", referencedColumnName="id", onDelete="SET NULL")
     */
    private ?BankTransaction $clientTransaction;

    public function __construct(
        WalletCompany $walletCompany,
        Invoice $invoice,
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate
    ) {
        $this->walletCompany     = $walletCompany;
        $this->invoice           = $invoice;
        $this->startDate         = $startDate;
        $this->endDate           = $endDate;
        $this->tiimeTransaction  = null;
        $this->clientTransaction = null;
    }

    public function registerDebit(BankTransaction $tiimeTransaction, BankTransaction $clientTransaction): void
    {
        $this->tiimeTransaction  = $tiimeTransaction;
        $this->clientTransaction = $clientTransaction;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWalletCompany(): WalletCompany
    {
        return $this->walletCompany;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function getStartDate(): \DateTimeInterface
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeInterface
    {
        return $this->endDate;
    }
}
