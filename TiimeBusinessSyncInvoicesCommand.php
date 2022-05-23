<?php

namespace App\Command;

use App\Accounting\Core\Handler\LabelHandler;
use App\Entity\BankTransaction;
use App\Entity\Document\Receipt;
use App\Entity\TiimeBusiness\TiimeBusinessInvoicing;
use App\Enums\EnumBankTransactionOperationType;
use App\Enums\EnumBankTransactionUserType;
use App\Enums\EnumCurrency;
use App\Enums\EnumDocumentSource;
use App\Enums\EnumInvoiceBankTransactionMatchingType;
use App\Enums\EnumSource;
use App\Handler\FileHandler;
use App\Handler\InvoiceHandler;
use App\Handler\Matching\InvoiceMatcherHandler;
use App\Handler\Matching\ReceiptMatcherHandler;
use App\Handler\ReceiptHandler;
use App\Handler\Wallet\TiimeBusinessBillingHandler;
use App\Handler\Wallet\WalletBankAccountHandler;
use App\Repository\BankTransactionRepository;
use App\Repository\CountryRepository;
use App\Repository\Document\DocumentCategoryRepository;
use App\Traits\EntityManagerAwareTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'tiime-business:sync-invoices',
    description: 'Catch TB invoices, verify and generate accounting, receipts and matching',
)]
class TiimeBusinessSyncInvoicesCommand extends Command
{
    use EntityManagerAwareTrait;

    public function __construct(
        private TiimeBusinessBillingHandler $tiimeBusinessBillingHandler,
        private CountryRepository           $countryRepository,
        private BankTransactionRepository   $bankTransactionRepository,
        private FileHandler                 $fileHandler,
        private ReceiptHandler              $receiptHandler,
        private InvoiceHandler              $invoiceHandler,
        private DocumentCategoryRepository  $documentCategoryRepository,
        private LabelHandler                $labelHandler,
        private InvoiceMatcherHandler       $invoiceMatcherHandler,
        private ReceiptMatcherHandler       $receiptMatcherHandler,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tiime = $this->tiimeBusinessBillingHandler->getTiimeCompany();

        $records = $this->entityManager->createQueryBuilder()
            ->select('tiime_business_invoicing')
            ->from(TiimeBusinessInvoicing::class, 'tiime_business_invoicing')
            ->where('tiime_business_invoicing.endDate = :endDate')
            ->setParameters([
                ':endDate' => '2022-03-31'
            ])
            ->getQuery()
            ->getResult();

        $treezorAccountToCheck = [];

        /** @var TiimeBusinessInvoicing $record */
        foreach ($records as $record) {
            $clientCompany = $record->getWalletCompany()->getCompany();

            $this->entityManager->getFilters()->disable('deleted_bank_transaction');

//            $date = '2022-05-16';
 //           $date = '2022-05-13';
            $date = '2022-04-28';

            $clientTransactionRefused = $this->entityManager->createQueryBuilder()
                ->select('bank_transaction')
                ->from(BankTransaction::class, 'bank_transaction')
                ->where('bank_transaction.bankAccount = :bankAccount')
                ->andWhere('bank_transaction.wording = :wording')
                ->andWhere('bank_transaction.transactionDate = :date')
                ->andWhere('bank_transaction.deletedAt IS NOT NULL')
                ->setParameters([
                    ':bankAccount' => $clientCompany->getWalletCompany()->getWalletBankAccount()->getBankAccount(),
                    ':date' => $date,
                    ':wording' => 'Tiime Business',
                ])
                ->getQuery()
                ->getOneOrNullResult();

            $this->entityManager->getFilters()->enable('deleted_bank_transaction');

            // Pass if bad payer
            if ($clientTransactionRefused instanceof BankTransaction) {
                continue;
            }

            $clientTransaction = $this->entityManager->createQueryBuilder()
                ->select('bank_transaction')
                ->from(BankTransaction::class, 'bank_transaction')
                ->where('bank_transaction.bankAccount = :bankAccount')
                ->andWhere('bank_transaction.wording = :wording')
                ->andWhere('bank_transaction.transactionDate = :date')
                ->setParameters([
                    ':bankAccount' => $clientCompany->getWalletCompany()->getWalletBankAccount()->getBankAccount(),
                    ':date' => $date,
                    ':wording' => 'Tiime Business'
                ])
                ->getQuery()
                ->getOneOrNullResult();

            // Pass if transfer not found
            if (false === $clientTransaction instanceof BankTransaction) {
                continue;
            }

            $tiimeTransaction = $this->entityManager->createQueryBuilder()
                ->select('bank_transaction')
                ->from(BankTransaction::class, 'bank_transaction')
                ->where('bank_transaction.bankAccount = :bankAccount')
                ->andWhere('bank_transaction.wording = :wording')
                ->andWhere('bank_transaction.transactionDate = :date')
                ->setParameters([
                    ':bankAccount' => $tiime->getWalletCompany()->getWalletBankAccount()->getBankAccount(),
                    ':date' => $date,
                    ':wording' => 'Tiime Business - ' . $clientCompany->getName() . ' - ' . $clientCompany->getSiret()
                ])
                ->getQuery()
                ->getOneOrNullResult();

            $clientReceipt = $this->entityManager->createQueryBuilder()
                ->select('receipt')
                ->from(Receipt::class, 'receipt')
                ->where('receipt.salesforceCompany = :company')
                ->andWhere('receipt.date = :date')
                ->andWhere('receipt.wording = :wording')
                ->setParameters([
                    ':company' => $clientCompany,
                    ':date' => $record->getInvoice()->getEmissionDate(),
                    ':wording' => 'Tiime Business'
                ])
                ->getQuery()
                ->getOneOrNullResult();


            if (false === $tiimeTransaction instanceof BankTransaction) {
                $wording = implode(' - ', ['Tiime Business', $clientCompany->getName(), $clientCompany->getSiret()]);

                $tiimeTransaction = (new BankTransaction())
                    ->setAmount(abs($clientTransaction->getAmount()))
                    ->setCurrency($clientTransaction->getCurrency())
                    ->setBankAccount($tiime->getWalletCompany()->getWalletBankAccount()->getBankAccount())
                    ->setOperationType(EnumBankTransactionOperationType::TRANSFER)
                    ->setRealizationDate($clientTransaction->getRealizationDate())
                    ->setTransactionDate($clientTransaction->getTransactionDate())
                    ->setVatApplicationDate($clientTransaction->getVatApplicationDate())
                    ->setWording($wording)
                    ->setOriginalWording($wording)
                    ->setCountry($this->countryRepository->getCountryByCode('FR'))
                    ->setCreatedBy(EnumBankTransactionUserType::WALLET);

                $this->bankTransactionRepository->save($tiimeTransaction);
            }

            if (true === $tiimeTransaction->getInvoiceBankTransactions()->isEmpty()) {
                $this->invoiceMatcherHandler->matchInvoiceBankTransaction($tiimeTransaction, $record->getInvoice(), EnumInvoiceBankTransactionMatchingType::CHRONOS);
            }

            try {
                if (false === $clientReceipt instanceof Receipt) {
                    $file = $this->fileHandler->temporaryFile($this->invoiceHandler->getInvoicePdf($record->getInvoice()));

                    $documentCategory = $this->documentCategoryRepository->getReservedCategory($clientCompany, Receipt::class);

                    $receipt = (new Receipt(EnumDocumentSource::ACCOUNTANT, $documentCategory, $record->getInvoice()->getPdfFilename()))
                        ->setAmount($record->getInvoice()->getTotalIncludingTaxes())
                        ->setCurrency(EnumCurrency::EURO)
                        ->setDate($record->getInvoice()->getEmissionDate())
                        ->setWording('Tiime Business')
                        ->setVatAmount($record->getInvoice()->getTotalIncludingTaxes() - $record->getInvoice()->getTotalExcludingTaxes());

                    if (true === $clientCompany->isTiimeExpert()) {
                        $receipt->setLabel($this->labelHandler->findOrCreateLabel($clientCompany, WalletBankAccountHandler::TIIME_BANK));
                    }

                    $this->receiptHandler->addReceipt($receipt, $file);
                    $this->receiptMatcherHandler->addReceiptBankTransactionMatching($clientTransaction, $receipt, false, EnumSource::CHRONOS);
                }
            } catch (\Exception $e) {
                dump($record->getInvoice()->getId());
            }
        }

        return Command::SUCCESS;
    }

    private function missingTransfers()
    {
//        $transfers = json_decode(file_get_contents('transfers.json'), true);
//
//        $missingTransfers = [];
//
//        foreach ($transfers as $transfer) {
//            $walletTransfer = $this->entityManager->createQueryBuilder()
//                ->select('wallet_transfer')
//                ->from(WalletTransfer::class, 'wallet_transfer')
//                ->where('wallet_transfer.treezorTransactionId = :transaction')
//                ->setParameters([
//                    ':transaction' => $transfer['transferId'],
//                ])
//                ->getQuery()
//                ->getOneOrNullResult();
//
//            if (false === $walletTransfer instanceof WalletTransfer) {
//                $missingTransfers[] = $transfer;
//            }
//        }
//
//        dd($missingTransfers);
//
//        foreach ($missingTransfers as $missingTransfer) {
//            $wba = $this->entityManager->createQueryBuilder()
//                ->select('wallet_bank_account')
//                ->from(WalletBankAccount::class, 'wallet_bank_account')
//                ->where('wallet_bank_account.treezorWalletId = :treezor')
//                ->setParameters([
//                    ':treezor' => $missingTransfer['walletId'],
//                ])
//                ->getQuery()
//                ->getOneOrNullResult();
//
//            if (false === $wba instanceof WalletBankAccount) {
//                throw new \Exception();
//            }
//
//            $walletTransfer = WalletBankTransactionFactory::getBankTransaction(new WalletTransferFactory(
//                $missingTransfer['transferId'],
//                -abs($missingTransfer['amount']),
//                $missingTransfer['currency'],
//                $wba,
//                new \DateTime($missingTransfer['createdDate']),
//                new \DateTime($missingTransfer['createdDate']),
//                new \DateTime($missingTransfer['createdDate']),
//                $this->countryRepository->getCountryByCode('FR'),
//                'Tiime Business'
//            ));
//
//            $this->entityManager->persist($walletTransfer);
//            $this->entityManager->flush();
//        }
    }
}
