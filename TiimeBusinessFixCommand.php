<?php

namespace App\Command;

use App\External\Treezor\BankApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

#[AsCommand(
    name: 'tiime-business:fix'
)]
class TiimeBusinessFixCommand extends Command
{
    public function __construct(
        private BankApi $bankApi
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->bankApi->getGuzzleClient();

        $transfersToTiime = [];

        for ($i = 1; $i < 200; $i++) {
            $res = $client->request(
                Request::METHOD_GET,
                'transfers',
                [
                    'query' => [
                        'transferTypeId' => '3',
                        'createdDateFrom' => '2022-06-01',
                        'beneficiaryWalletId' => '5399517',
                        'pageCount' => '200',
                        'pageNumber' => $i
                    ],
                ]
            );

            $treezorTransfers = json_decode($res->getBody()->getContents(), true);

            if(count($treezorTransfers['transfers']) === 0){
                break;
            }

            foreach ($treezorTransfers['transfers'] as $treezorTransfer) {
                $transfersToTiime[] = $treezorTransfer;
            }
        }

        $transfersToClient = [];

        for ($i = 1; $i < 200; $i++) {
            $res = $client->request(
                Request::METHOD_GET,
                'transfers',
                [
                    'query' => [
                        'transferTypeId' => '4',
                        'createdDateFrom' => '2022-06-01',
                        'walletId' => '5399517',
                        'pageCount' => '200',
                        'pageNumber' => $i
                    ],
                ]
            );

            $treezorTransfers = json_decode($res->getBody()->getContents(), true);

            if(count($treezorTransfers['transfers']) === 0){
                break;
            }

            foreach ($treezorTransfers['transfers'] as $treezorTransfer) {
                $transfersToClient[] = $treezorTransfer;
            }
        }

        file_put_contents("debit.json", json_encode($transfersToTiime));

        file_put_contents("refund.json", json_encode($transfersToClient));

        return Command::SUCCESS;
    }
}
