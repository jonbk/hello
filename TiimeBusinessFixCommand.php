<?php

namespace App\Command;

use App\Entity\TiimeBusiness\TiimeBusinessInvoicing;
use App\External\Treezor\BankApi;
use App\Traits\EntityManagerAwareTrait;
use Doctrine\ORM\AbstractQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'tiime-business:fix'
)]
class TiimeBusinessFixCommand extends Command
{
    use EntityManagerAwareTrait;


    public function __construct(
        private BankApi $bankApi
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->bankApi->getGuzzleClient();

        $invoices = $this->entityManager->createQueryBuilder()
            ->select('invoice.id as invoice_id')
            ->from(TiimeBusinessInvoicing::class, 'tiime_business_invoicing')
            ->innerJoin('tiime_business_invoicing.invoice', 'invoice')
            ->where('tiime_business_invoicing.startDate = :date')
            ->setParameters([
                ':date' => '2022-04-01'
            ])
            ->getQuery()
            ->getResult(AbstractQuery::HYDRATE_SCALAR);

        foreach ($invoices as $invoice) {
            $invoiceId = $invoice['invoice_id'];

            $res = $client->request(
                'GET',
                'transfers',
                [
                    'query' => [
                        'transferTag' => 'invoice_' . $invoiceId,
                    ]
                ]
            );

            $debits = json_decode($res->getBody()->getContents(), true)['transfers'];
            $toRefund = [];
            foreach ($debits as $index => $debit) {
                $res = $client->request(
                    'GET',
                    'transfers',
                    [
                        'query' => [
                            'transferTag' => 'credit_' . $debit['transferId'],
                        ]
                    ]
                );

                $refund = json_decode($res->getBody()->getContents(), true)['transfers'];

                if (true === empty($refund)) {
                    $toRefund[] = $debit;
                }
            }

            dd($toRefund);
        }

        return Command::SUCCESS;
    }
}
