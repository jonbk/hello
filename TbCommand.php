<?php

namespace App\Command;

use App\External\Treezor\BankApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

#[AsCommand(
    name: 'app:tb',
    description: 'Add a short description for your command',
)]
class TbCommand extends Command
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

        $res = $client->request(
            Request::METHOD_GET,
            'transfers',
            [
                'query' => [
                    'transferTypeId' => '3',
                    'createdDateFrom' => '2022-06-01',
                    'beneficiaryWalletId' => '5399517',
                    'pageCount' => '200',
                    'pageNumber' => '1',
                ]
            ]
        );

        $treezorTransfers = json_decode($res->getBody()->getContents(), true);
        $treezorTransfers = $treezorTransfers['transfers'][0];

        dd($treezorTransfers);

        return self::SUCCESS;
    }
}
