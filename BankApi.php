<?php

namespace App\External\Treezor;

use App\Constant\CompanyLegalFormsTreezor;
use App\Constant\TreezorCivility;
use App\Constant\TreezorControllingPersonType;
use App\Constant\TreezorEmployeeType;
use App\Constant\TreezorEntityType;
use App\Constant\TreezorParentType;
use App\Constant\TreezorPayinMethod;
use App\Constant\TreezorRejectReasonCode;
use App\Constant\TreezorSpecifiedUSPerson;
use App\Constant\TreezorUserType;
use App\Constant\TreezorWalletType;
use App\Entity\Interfaces\TreezorUserInterface;
use App\Entity\Invoicing\Invoice;
use App\Entity\SalesforceCompany;
use App\Entity\WalletCard;
use App\Entity\WalletCheck;
use App\Entity\WalletCompany;
use App\Entity\WalletUser;
use App\Enums\EnumCurrency;
use App\Enums\EnumDepositStatus;
use App\Enums\EnumDrawerType;
use App\Enums\EnumWalletBeneficiaryType;
use App\Enums\EnumWalletCardActivationStatus;
use App\Enums\EnumWalletCardDesign;
use App\Enums\EnumWalletCardStatus;
use App\Enums\EnumWalletCardTransactionStatus;
use App\Enums\EnumWalletDocumentStatus;
use App\Enums\EnumWalletPayinRefundStatus;
use App\Enums\EnumWalletTransferStatus;
use App\Event\Wallet\WalletUserPhoneUpdated;
use App\Exception\NotImplementedException;
use App\Exception\TiimeBusiness\WalletCheckException;
use App\Exception\WalletException;
use App\Handler\Wallet\WalletBankAccountHandler;
use App\Helper\BankHelper;
use App\Helper\SerializerHelper;
use App\Helper\WalletHelper;
use App\Logger\Formatter\Guzzle\ChronosMessageFormatter;
use App\Model\Address;
use App\Model\Treezor\Beneficiary as TreezorBeneficiary;
use App\Model\Treezor\Card as TreezorCard;
use App\Model\Treezor\Transfer as TreezorTransfer;
use App\Model\Treezor\User as TreezorUser;
use App\Model\Treezor\Wallet as TreezorWallet;
use App\Repository\CountryRepository;
use App\Traits\EntityManagerAwareTrait;
use App\Traits\EventDispatcherAwareTrait;
use App\Traits\LoggerAwareTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Serializer;
use Tiime\TechnicalDebtTracker\Annotation\TechnicalDebt;
use Tiime\TechnicalDebtTracker\Category;

class BankApi
{
    use EntityManagerAwareTrait;
    use EventDispatcherAwareTrait;
    use LoggerAwareTrait;

    private const CLIENT_FEES = 3;

    private Client $guzzleClient;
    private string $treezorTariffId;
    private string $treezorPermsGroup;
    private string $treezorCardPrint;
    private Serializer $serializer;
    private CountryRepository $countryRepository;

    public function __construct(
        LoggerInterface $logger,
        CountryRepository $countryRepository,
        string $guzzleLogFormat,
        string $treezorDomain,
        string $treezorToken,
        string $treezorTariffId,
        string $treezorPermsGroup,
        string $treezorCardPrint
    ) {
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) {
            $response->getBody()->rewind();

            return $response;
        }));
        $stack->push(Middleware::log($logger, new ChronosMessageFormatter($guzzleLogFormat)));

        $this->guzzleClient = new Client(
            [
                'base_uri' => $treezorDomain,
                'headers'  => [
                    'Authorization' => 'Bearer ' . $treezorToken,
                    'Content-Type'  => 'application/json',
                ],
                'handler' => $stack,
            ]
        );

        $this->treezorTariffId   = $treezorTariffId;
        $this->treezorPermsGroup = $treezorPermsGroup;
        $this->treezorCardPrint  = $treezorCardPrint;
        $this->serializer        = SerializerHelper::getWalletSerializer();
        $this->countryRepository = $countryRepository;
    }

    /**
     * The results returned by Treezor are always arrays even for one element so we need only the first element.
     * This method is used to improve readability of the code by catching only the first and only expected element in the results.
     *
     * @throws WalletException
     */
    public static function getOnlyElement(array $array): array
    {
        if (0 === \count($array)) {
            throw new WalletException(
                Response::HTTP_NOT_FOUND,
                'Erreur avec la couche d\'accès à l\'API bancaire. Le retour est vide.',
                null,
                'exception.wallet.bank_api.empty_result'
            );
        }

        if (1 < \count($array)) {
            throw new WalletException(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Erreur avec la couche d\'accès à l\'API bancaire. Le service a retourné ' . \count($array) . ' résultats.',
                null,
                'exception.wallet.bank_api.too_many_results'
            );
        }

        return $array[0];
    }

    public function createUser(WalletUser $walletUser): TreezorUser
    {
        if (true === $walletUser->getWalletCompany()->getCompany()->isIndividualCompany()) {
            $walletUser->setTreezorUserId($walletUser->getWalletCompany()->getTreezorUserId());

            return $this->updateUser($walletUser);
        }

        $res = $this->guzzleClient->request(
            Request::METHOD_POST,
            'users',
            [
                'json' => array_merge(
                    ['accessTag' => $this->generateAccessTag('createUser', $walletUser->getTreezorEmail())],
                    $this->formatUserBody($walletUser)
                ),
            ]
        );

        $treezorUsers = json_decode($res->getBody()->getContents(), true);
        $treezorUser  = self::getOnlyElement($treezorUsers['users']);

        return $this->serializer->denormalize($treezorUser, TreezorUser::class);
    }

    public function createCheck(WalletCheck $check)
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_POST,
                'payins',
                [
                    'json' => [
                        'walletId'        => $check->getWalletBankAccount()->getTreezorWalletId(),
                        'paymentMethodId' => TreezorPayinMethod::CHECK,
                        'amount'          => $check->getAmount(),
                        'currency'        => EnumCurrency::EURO,
                        'additionalData'  => [
                            'cheque' => [
                                'cmc7' => [
                                    'a' => mb_substr($check->getCmc7(), 0, 7),
                                    'b' => mb_substr($check->getCmc7(), 7, 12),
                                    'c' => mb_substr($check->getCmc7(), 19, 12),
                                ],
                                'RLMCKey'    => $check->getRlmcKey(),
                                'drawerData' => [
                                    'firstName'       => EnumDrawerType::PERSON === $check->getDrawerType() ? $check->getDrawerFirstname() : '',
                                    'lastName'        => EnumDrawerType::PERSON === $check->getDrawerType() ? $check->getDrawerLastname() : $check->getDrawer(),
                                    'isNaturalPerson' => EnumDrawerType::toTreezor($check->getDrawerType()),
                                ],
                            ],
                        ],
                    ],
                ]
            );

            $contentTreezor = json_decode($res->getBody()->getContents(), true);
            $treezorPayin   = self::getOnlyElement($contentTreezor['payins']);

            return self::convertTreezorPayinToTypedArray($treezorPayin);
        } catch (GuzzleException $e) {
            $this->logger->error('exception.tiime_business.wallet_check.error_creation_treezor', [
                'error_message' => $e->getMessage(),
            ]);

            throw new WalletCheckException(
                $e->getCode(),
                'Erreur lors de l\’envoi des informations vers notre partenaire.',
                $e,
                'exception.tiime_business.wallet_check.error_creation_treezor'
            );
        }
    }

    /**
     * @throws ExceptionInterface
     * @throws GuzzleException
     * @throws WalletException
     */
    public function getUser(TreezorUserInterface $treezorUser): TreezorUser
    {
        $res = $this->guzzleClient->request(
            Request::METHOD_GET,
            'users/' . $treezorUser->getTreezorUserId()
        );

        $treezorUsers = json_decode($res->getBody()->getContents(), true);
        $treezorUser  = self::getOnlyElement($treezorUsers['users']);

        return $this->serializer->denormalize($treezorUser, TreezorUser::class);
    }

    /**
     * @throws ExceptionInterface
     * @throws GuzzleException
     * @throws WalletException
     */
    public function updateUser(WalletUser $walletUser, array $fields = []): TreezorUser
    {
        $diff = $this->userDiff($walletUser, $fields);

        if (true === empty($diff)) {
            $this->logger->info('Treezor user is already sync', [
                'wallet_user_id' => $walletUser->getId(),
                'fields'         => $fields,
            ]);

            return $this->serializer->denormalize($this->formatUserBody($walletUser), TreezorUser::class);
        }

        $res = $this->guzzleClient->request(
            Request::METHOD_PUT,
            'users/' . $walletUser->getTreezorUserId(),
            ['json' => $diff]
        );

        $treezorUsers = json_decode($res->getBody()->getContents(), true);

        if (true === \array_key_exists('phone', $diff)) {
            $this->eventDispatcher->dispatch(new WalletUserPhoneUpdated($walletUser));
        }

        return $this->serializer->denormalize(self::getOnlyElement($treezorUsers['users']), TreezorUser::class);
    }

    public function userDiff(WalletUser $walletUser, array $fields = []): array
    {
        $serializer  = SerializerHelper::getWalletSerializer();
        $updatedUser = $this->formatUserBody($walletUser, $fields);
        $treezorUser = $this->getUser($walletUser);

        return array_diff_assoc($updatedUser, $serializer->normalize($treezorUser));
    }

    /**
     * @TechnicalDebt(
     *     categories={Category::DELAYED_REFACTORING},
     *     reporter="JO",
     *     description="WalletCompany should have treezorUserId before create walletUser"
     * )
     */
    public function formatUserBody(WalletUser $walletUser, array $fields = []): array
    {
        $salesforceUser = $walletUser->getSalesforceUser();

        $country = $this->countryRepository->getCountryByCodeOrName($salesforceUser->getMailingCountry());

        if (true === $walletUser->getWalletCompany()->getCompany()->isIndividualCompany()) {
            $allFields = $this->formatIndividualCompanyBody($walletUser->getWalletCompany());
        } else {
            $allFields = [
                'userTypeId'            => TreezorUserType::PHYSICAL_PERSON,
                'email'                 => $walletUser->getTreezorEmail(),
                'title'                 => TreezorCivility::toTreezor($salesforceUser->getCivility()),
                'firstname'             => $salesforceUser->getFirstName(),
                'lastname'              => $salesforceUser->getLastName(),
                'birthday'              => $salesforceUser->getBirthDate()->format('Y-m-d'),
                'address1'              => $salesforceUser->getMailingStreet(),
                'postcode'              => $salesforceUser->getMailingPostalCode(),
                'city'                  => $salesforceUser->getMailingCity(),
                'country'               => $country->getCode(),
                'nationality'           => $salesforceUser->getNationality()->getCode(),
                'placeOfBirth'          => $salesforceUser->getBirthPlace(),
                'birthCountry'          => $salesforceUser->getBirthCountry()->getCode(),
                'phone'                 => $salesforceUser->getMobilePhone(),
                'parentUserId'          => $walletUser->getWalletCompany()->getTreezorUserId(),
                'parentType'            => $walletUser->getSalesforceStakeholder()->isDirector() ? TreezorParentType::LEADER : TreezorParentType::SHAREHOLDER, // @deprecated To remove one day, when Treezor will say so
                'employeeType'          => $walletUser->getSalesforceStakeholder()->isDirector() ? TreezorEmployeeType::LEADER : TreezorEmployeeType::NONE,
                'controllingPersonType' => $walletUser->getSalesforceStakeholder()->isBeneficiary() ? TreezorControllingPersonType::SHAREHOLDER : TreezorControllingPersonType::DIRECTOR,
                'specifiedUSPerson'     => TreezorSpecifiedUSPerson::NO,
                'effectiveBeneficiary'  => $walletUser->getSalesforceStakeholder()->getEffectiveBeneficiaryPercentage(),
            ];
        }

        if (false === empty($fields)) {
            return array_intersect_key($allFields, array_flip($fields));
        }

        return $allFields;
    }

    /**
     * @throws WalletException
     * @throws ExceptionInterface
     */
    public function createKycRequest(string $treezorUserId): TreezorUser
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_PUT,
                'users/' . $treezorUserId . '/Kycreview/'
            );
        } catch (GuzzleException $e) {
            $this->logger->error('exception.wallet.bank_api.create_kyc_request_error', [
                'treezor_user_id' => $treezorUserId,
                'error_message'   => $e->getMessage(),
            ]);

            throw new WalletException(
                $e->getCode(),
                'Erreur lors de l\'envoi des KYC/KYB pour l\'utilisateur ' . $treezorUserId,
                $e,
                'exception.wallet.bank_api.create_kyc_request_error'
            );
        }

        $treezorUsers = json_decode($res->getBody()->getContents(), true);
        $treezorUser  = $this->getOnlyElement($treezorUsers['users']);

        return $this->serializer->denormalize($treezorUser, TreezorUser::class);
    }

    public function createCompany(WalletCompany $walletCompany): TreezorUser
    {
        $res = $this->guzzleClient->request(
            Request::METHOD_POST,
            'users',
            [
                'json' => array_merge(
                    ['accessTag' => $this->generateAccessTag('createCompany', $walletCompany->getTreezorEmail())],
                    $this->formatCompanyBody($walletCompany)
                ),
            ]
        );

        $treezorUsers = json_decode($res->getBody()->getContents(), true);
        $treezorUser  = self::getOnlyElement($treezorUsers['users']);

        return $this->serializer->denormalize($treezorUser, TreezorUser::class);
    }

    /**
     * @throws ExceptionInterface
     * @throws GuzzleException
     * @throws WalletException
     */
    public function updateCompany(WalletCompany $walletCompany): TreezorUser
    {
        $res = $this->guzzleClient->request(
            Request::METHOD_PUT,
            'users/' . $walletCompany->getTreezorUserId(),
            [
                'json' => $this->formatCompanyBody($walletCompany),
            ]
        );

        $treezorUsers = json_decode($res->getBody()->getContents(), true);
        $treezorUser  = self::getOnlyElement($treezorUsers['users']);

        return $this->serializer->denormalize($treezorUser, TreezorUser::class);
    }

    public function formatCompanyBody(WalletCompany $walletCompany): array
    {
        if (true === $walletCompany->getCompany()->isIndividualCompany()) {
            return $this->formatIndividualCompanyBody($walletCompany);
        }

        $userTypeId = TreezorUserType::CORPORATION;
        $country    = $this->countryRepository->findOneBy(['name' => $walletCompany->getCompany()->getCountry()]);

        return [
            'userTypeId'                 => $userTypeId,
            'email'                      => $walletCompany->getTreezorEmail(),
            'legalName'                  => $walletCompany->getCompany()->getName(),
            'legalForm'                  => CompanyLegalFormsTreezor::toTreezor($walletCompany->getCompany()->getLegalForm()),
            'legalSector'                => $walletCompany->getCompany()->getApeCode()->getCode(),
            'legalRegistrationNumber'    => $walletCompany->getCompany()->getSiret(),
            'legalRegistrationDate'      => $walletCompany->getCompany()->getRegistrationDate()->format('Y-m-d'),
            'legalAnnualTurnOver'        => $walletCompany->getCompany()->getAnnualTurnover(),
            'legalNumberOfEmployeeRange' => $walletCompany->getCompany()->getEmployeesRange(),
            'legalNetIncomeRange'        => $walletCompany->getCompany()->getLastNetIncomeRange(),
            'address1'                   => $walletCompany->getCompany()->getStreet(),
            'postcode'                   => $walletCompany->getCompany()->getPostalCode(),
            'city'                       => $walletCompany->getCompany()->getCity(),
            'country'                    => $country->getCode(),
            'phone'                      => $walletCompany->getCompany()->getPhone(),
            'entityType'                 => TreezorEntityType::ACTIVE_NON_FINANCIAL_OTHER,
            'specifiedUSPerson'          => TreezorSpecifiedUSPerson::NO,
            'activityOutsideEu'          => $walletCompany->isActivityOutsideEu(),
            'economicSanctions'          => $walletCompany->isEconomicSanctions(),
            'residentCountriesSanctions' => $walletCompany->isResidentCountriesSanctions(),
            'involvedSanctions'          => $walletCompany->isInvolvedSanctions(),
        ];
    }

    private function formatIndividualCompanyBody(WalletCompany $walletCompany)
    {
        $walletUserBody = [];

        if (($walletUser = $walletCompany->getWalletUsers()->first()) instanceof WalletUser) {
            $country = $this->countryRepository->getCountryByCodeOrName($walletUser->getSalesforceUser()->getMailingCountry());

            $walletUserBody = [
                'email'                 => $walletUser->getTreezorEmail(),
                'title'                 => TreezorCivility::toTreezor($walletUser->getSalesforceUser()->getCivility()),
                'firstname'             => $walletUser->getSalesforceUser()->getFirstName(),
                'lastname'              => $walletUser->getSalesforceUser()->getLastName(),
                'birthday'              => $walletUser->getSalesforceUser()->getBirthDate()->format('Y-m-d'),
                'address1'              => $walletUser->getSalesforceUser()->getMailingStreet(),
                'postcode'              => $walletUser->getSalesforceUser()->getMailingPostalCode(),
                'city'                  => $walletUser->getSalesforceUser()->getMailingCity(),
                'country'               => $country->getCode(),
                'nationality'           => $walletUser->getSalesforceUser()->getNationality()->getCode(),
                'placeOfBirth'          => $walletUser->getSalesforceUser()->getBirthPlace(),
                'birthCountry'          => $walletUser->getSalesforceUser()->getBirthCountry()->getCode(),
                'phone'                 => $walletUser->getSalesforceUser()->getMobilePhone(),
                'parentUserId'          => $walletUser->getWalletCompany()->getTreezorUserId(),
                'parentType'            => $walletUser->getSalesforceStakeholder()->isDirector() ? TreezorParentType::LEADER : TreezorParentType::SHAREHOLDER, // @deprecated To remove one day, when Treezor will say so
                'employeeType'          => $walletUser->getSalesforceStakeholder()->isDirector() ? TreezorEmployeeType::LEADER : TreezorEmployeeType::NONE,
                'controllingPersonType' => $walletUser->getSalesforceStakeholder()->isBeneficiary() ? TreezorControllingPersonType::SHAREHOLDER : TreezorControllingPersonType::DIRECTOR,
                'incomeRange'           => $walletUser->getSalesforceUser()->getIncomeRange(),
                'personalAssets'        => $walletUser->getSalesforceUser()->getPersonalAssetsRange(),
            ];
        }

        return $walletUserBody + [
            'email'                      => $walletCompany->getCompany()->getEmail(),
            'userTypeId'                 => TreezorUserType::PHYSICAL_PERSON,
            'specifiedUSPerson'          => TreezorSpecifiedUSPerson::NO,
            'legalName'                  => $walletCompany->getCompany()->getName(),
            'legalForm'                  => CompanyLegalFormsTreezor::toTreezor($walletCompany->getCompany()->getLegalForm()),
            'legalSector'                => $walletCompany->getCompany()->getApeCode()->getCode(),
            'legalRegistrationNumber'    => $walletCompany->getCompany()->getSiret(),
            'legalRegistrationDate'      => $walletCompany->getCompany()->getRegistrationDate()->format('Y-m-d'),
            'legalAnnualTurnOver'        => '0-39',
            'legalNumberOfEmployeeRange' => $walletCompany->getCompany()->getEmployeesRange(),
            'legalNetIncomeRange'        => '0-4',
            'entityType'                 => TreezorEntityType::ACTIVE_NON_FINANCIAL_OTHER,
            'activityOutsideEu'          => $walletCompany->isActivityOutsideEu(),
            'economicSanctions'          => $walletCompany->isEconomicSanctions(),
            'residentCountriesSanctions' => $walletCompany->isResidentCountriesSanctions(),
            'involvedSanctions'          => $walletCompany->isInvolvedSanctions(),
        ];
    }

    public function createWallet(WalletCompany $walletCompany): TreezorWallet
    {
        $res = $this->guzzleClient->request(
            Request::METHOD_POST,
            'wallets',
            [
                'json' => [
                    'accessTag'    => $this->generateAccessTag('createWallet', $walletCompany->getTreezorUserId()),
                    'walletTypeId' => TreezorWalletType::PAYMENT_ACCOUNT,
                    'tariffId'     => $this->treezorTariffId,
                    'userId'       => $walletCompany->getTreezorUserId(),
                    'currency'     => EnumCurrency::EURO,
                    'eventName'    => WalletBankAccountHandler::TIIME_WALLET,
                ],
            ]
        );

        $treezorWallets = json_decode($res->getBody()->getContents(), true);
        $treezorWallet  = self::getOnlyElement($treezorWallets['wallets']);

        return $this->serializer->denormalize($treezorWallet, TreezorWallet::class);
    }

    /**
     * @throws WalletException
     */
    public function createPayout(
        int $walletId,
        int $beneficiaryId,
        float $amount,
        ?string $label,
        ?int $walletPayoutScheduleId = null,
        string $currency = EnumCurrency::EURO,
        ?string $documentUrl = null
    ): array {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_POST,
                'payouts',
                [
                    'json' => [
                        'accessTag' => $this->generateAccessTag(
                            'createPayout',
                            $walletId,
                            $beneficiaryId,
                            $walletPayoutScheduleId,
                            $amount,
                            $label,
                            (new \DateTime())->format('Y-m-d H:i')
                        ),
                        'walletId'           => (string) $walletId,
                        'beneficiaryId'      => (string) $beneficiaryId,
                        'amount'             => (string) $amount,
                        'label'              => $label,
                        'currency'           => $currency,
                        'supportingFileLink' => $documentUrl,
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                'Erreur lors de la création d\'un virement avec l\'API bancaire (' . $walletId . ', ' . $beneficiaryId . ', ' . $amount . ', ' . $label . ', ' . $currency . ')',
                $e,
                'exception.wallet.bank_api.create_transfer_error'
            );
        }

        $treezorPayouts = json_decode($res->getBody()->getContents(), true);
        $treezorPayout  = self::getOnlyElement($treezorPayouts['payouts']);

        return self::convertTreezorPayoutToTypedArray($treezorPayout);
    }

    public function cancelPayout(
        int $payoutId
    ): array {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_DELETE,
                'payouts/' . $payoutId
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                'Erreur lors de l\'annulation d\'un virement avec l\'API bancaire (' . $payoutId . ')',
                $e,
                'exception.wallet.bank_api.cancel_transfer_error'
            );
        }

        $treezorPayouts = json_decode($res->getBody()->getContents(), true);
        $treezorPayout  = self::getOnlyElement($treezorPayouts['payouts']);

        return self::convertTreezorPayoutToTypedArray($treezorPayout);
    }

    /**
     * @throws WalletException
     */
    public function createBeneficiary(string $treezorUserId, string $name, string $iban, string $bic): array
    {
        if (false === BankHelper::inSepaZone($iban)) {
            throw WalletException::cannotUseBeneficiaryOutsideSepaZone();
        }

        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_POST,
                'beneficiaries',
                [
                    'json' => [
                        'accessTag'    => $this->generateAccessTag('createBeneficiary', $treezorUserId, $iban),
                        'userId'       => $treezorUserId,
                        'name'         => $name,
                        'iban'         => $iban,
                        'bic'          => $bic,
                        'usableForSct' => true,
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la création d\'un bénéficiaire avec l\'API bancaire (%s, %s, %s, %s)', $treezorUserId, $name, $iban, $bic),
                $e,
                'exception.wallet.bank_api.get_beneficiary_error'
            );
        }

        $treezorBeneficiaries = json_decode($res->getBody()->getContents(), true);
        $treezorBeneficiary   = self::getOnlyElement($treezorBeneficiaries['beneficiaries']);

        return self::convertTreezorBeneficiaryToTypedArray($treezorBeneficiary);
    }

    /**
     * @throws WalletException
     */
    public function getBeneficiary(string $treezorBeneficiaryId): array
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_GET,
                'beneficiaries/' . $treezorBeneficiaryId,
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la récupération du bénéficiaire %d avec l\'API bancaire', $treezorBeneficiaryId),
                $e,
                'exception.wallet.bank_api.create_beneficiary_error'
            );
        }

        $treezorBeneficiaries = json_decode($res->getBody()->getContents(), true);
        $treezorBeneficiary   = self::getOnlyElement($treezorBeneficiaries['beneficiaries']);

        return self::convertTreezorBeneficiaryToTypedArray($treezorBeneficiary);
    }

    /**
     * @throws WalletException
     */
    public function createB2bDebtor(int $treezorCompanyId, string $name, string $ics, string $rum, string $address, bool $isRecurrent = false): TreezorBeneficiary
    {
        $beneficiary = (new TreezorBeneficiary())
            ->setAddress($address)
            ->setName($name)
            ->addSddB2bToWhitelist([
                'uniqueMandateReference' => $rum,
                'isRecurrent'            => $isRecurrent,
            ])
            ->setSepaCreditorIdentifier($ics)
            ->setUsableForSct(false)
            ->setUserId($treezorCompanyId);

        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_POST,
                'beneficiaries',
                ['json' => $this->serializer->normalize($beneficiary, null, ['groups' => 'CreateDebtor'])]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la création d\'un débiteur B2B avec l\'API bancaire (%s, %s, %s, %s, %s)', $treezorCompanyId, $name, $ics, $rum, $isRecurrent),
                $e
            );
        }

        $treezorBeneficiaries = json_decode($res->getBody()->getContents(), true);
        $treezorBeneficiary   = self::getOnlyElement($treezorBeneficiaries['beneficiaries']);

        return $this->serializer->denormalize($treezorBeneficiary, TreezorBeneficiary::class, null, ['groups' => 'Debtor']);
    }

    /**
     * @throws WalletException
     */
    public function getB2bDebtor(int $treezorBeneficiaryId): TreezorBeneficiary
    {
        try {
            $res = $this->guzzleClient->request(Request::METHOD_GET, 'beneficiaries/' . $treezorBeneficiaryId);
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la récupération du bénéficiaire %d avec l\'API bancaire', $treezorBeneficiaryId),
                $e,
                'exception.wallet.bank_api.get_beneficiary_error'
            );
        }

        $treezorBeneficiaries = json_decode($res->getBody()->getContents(), true);
        $treezorBeneficiary   = self::getOnlyElement($treezorBeneficiaries['beneficiaries']);

        return $this->serializer->denormalize($treezorBeneficiary, TreezorBeneficiary::class, null, ['groups' => 'Debtor']);
    }

    /**
     * @throws WalletException
     */
    public function updateB2bDebtor(TreezorBeneficiary $beneficiary): void
    {
        try {
            $this->guzzleClient->request(
                Request::METHOD_PUT,
                'beneficiaries/' . $beneficiary->getId(),
                ['json' => $this->serializer->normalize($beneficiary, null, ['groups' => 'UpdateDebtor'])]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la mis à jour du bénéficiaire %d avec l\'API bancaire', $beneficiary->getId()),
                $e,
                'exception.wallet.bank_api.update_beneficiary_error'
            );
        }
    }

    /**
     * @throws WalletException
     */
    public function blacklistBeneficiarySdd(string $beneficiaryId): void
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_PUT,
                "beneficiaries/$beneficiaryId",
                [
                    'json' => [
                        'sddCoreBlacklist' => ['*'],
                        'sddB2bWhitelist'  => [],
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors du blacklistage du bénéficiaire %d', $beneficiaryId),
                $e,
                'exception.wallet.bank_api.blacklist_beneficiary_error'
            );
        }
    }

    /**
     * @throws WalletException
     */
    public function activateCard(string $treezorCardId): array
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_PUT,
                "cards/$treezorCardId/Activate/"
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                'Erreur lors de l\'activation d\'une carte avec l\'API bancaire (' . $treezorCardId . ')',
                $e,
                'exception.wallet.bank_api.activate_card_error'
            );
        }

        $treezorCards = json_decode($res->getBody()->getContents(), true);
        $treezorCard  = self::getOnlyElement($treezorCards['cards']);

        return self::convertTreezorCardToTypedArray($treezorCard);
    }

    /**
     * @throws WalletException
     */
    public function modifyCardStatus(string $treezorCardId, string $status): array
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_PUT,
                "cards/$treezorCardId/LockUnlock/",
                [
                    'json' => [
                        'lockStatus' => EnumWalletCardStatus::toTreezor($status),
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                'Erreur lors du changement de statut d\'une carte avec l\'API bancaire (' . $treezorCardId . ', ' . $status . ')',
                $e,
                'exception.wallet.bank_api.modify_card_error'
            );
        }

        $treezorCards = json_decode($res->getBody()->getContents(), true);
        $treezorCard  = self::getOnlyElement($treezorCards['cards']);

        return self::convertTreezorCardToTypedArray($treezorCard);
    }

    /**
     * @throws WalletException
     */
    public function modifyCardPin(string $treezorCardId, string $newPin, string $confirmPin): array
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_PUT,
                "cards/$treezorCardId/setPIN/",
                [
                    'json' => [
                        'newPIN'     => $newPin,
                        'confirmPIN' => $confirmPin,
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                'Erreur lors du changement de code pin d\'une carte avec l\'API bancaire (' . $treezorCardId . ')',
                $e,
                'exception.wallet.bank_api.modify_card_pin'
            );
        }

        $treezorCards = json_decode($res->getBody()->getContents(), true);
        $treezorCard  = self::getOnlyElement($treezorCards['cards']);

        return self::convertTreezorCardToTypedArray($treezorCard);
    }

    /**
     * @throws WalletException
     */
    public function unblockCardPin(int $treezorCardId): array
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_PUT,
                "cards/$treezorCardId/UnblockPIN/"
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                'BankApi:unblockCardPin : Erreur lors du déblocage de code pin d\'une carte avec l\'API bancaire (' . $treezorCardId . ')',
                $e
            );
        }

        $treezorCards = json_decode($res->getBody()->getContents(), true);
        $treezorCard  = self::getOnlyElement($treezorCards['cards']);

        return self::convertTreezorCardToTypedArray($treezorCard);
    }

    /**
     * @throws WalletException
     */
    private function getBalanceRaw(array $parameters): array
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_GET,
                'balances',
                [
                    'query' => $parameters,
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                'Erreur lors de la récupération de la balance avec l\'API bancaire',
                $e,
                'exception.wallet.bank_api.get_balance_error'
            );
        }
        $treezorBalances = json_decode($res->getBody()->getContents(), true);

        return $treezorBalances['balances'];
    }

    /**
     * @throws WalletException
     */
    public function getBalanceRawByWalletId(string $walletId): array
    {
        return $this->getBalanceRaw(['walletId' => $walletId]);
    }

    /**
     * @throws WalletException
     */
    public function createDocument(string $userId, int $documentTypeId, string $name, string $fileContentBase64): array
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_POST,
                'documents',
                [
                    'json' => [
                        'userId'            => $userId,
                        'documentTypeId'    => $documentTypeId,
                        'name'              => $name,
                        'fileContentBase64' => $fileContentBase64,
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            $this->logger->error('exception.wallet.bank_api.create_document_error', [
                'user_id'          => $userId,
                'document_type_id' => $documentTypeId,
                'name'             => $name,
                'error_message'    => $e->getMessage(),
            ]);

            throw new WalletException(
                $e->getCode(),
                'Erreur lors de la création du document avec l\'API bancaire (' . $userId . ', ' . $documentTypeId . ', ' . $name . ')',
                $e,
                'exception.wallet.bank_api.create_document_error'
            );
        }

        $treezorDocuments = json_decode($res->getBody()->getContents(), true);
        $treezorDocument  = self::getOnlyElement($treezorDocuments['documents']);

        return self::convertTreezorDocumentToTypedArray($treezorDocument);
    }

    /**
     * @throws WalletException
     * @throws NotImplementedException
     */
    public function createPhysicalCard(WalletCard $walletCard): array
    {
        $treezorCard = $this->createVirtualCard($walletCard);

        return $this->convertToPhysicalCard($treezorCard['card_id'], $walletCard->getDeliveryAddress());
    }

    /**
     * @TechnicalDebt(
     *     reporter="rflavien",
     *     categories={Category::BAD_PRACTICE},
     *     description="variables d'env à injecter"
     * )
     *
     * @throws WalletException
     * @throws NotImplementedException
     */
    private function createVirtualCard(WalletCard $walletCard): array
    {
        $walletUser = $walletCard->getWalletUser();

        try {
            $optionals = [];

            $title = TreezorCivility::toTreezor($walletCard->getDeliveryAddress()->getTitle());

            if (null !== $title) {
                $optionals['deliveryTitle'] = $title;
            }

            if (null !== $walletCard->getDeliveryAddress()->getAdditionalInformation1()) {
                $optionals['deliveryAddress2'] = $walletCard->getDeliveryAddress()->getAdditionalInformation1();
            }

            if (null !== $walletCard->getDeliveryAddress()->getAdditionalInformation2()) {
                $optionals['deliveryAddress3'] = $walletCard->getDeliveryAddress()->getAdditionalInformation2();
            }

            $res = $this->guzzleClient->request(
                Request::METHOD_POST,
                'cards/CreateVirtual',
                [
                    'json' => array_merge(
                        [
                            'accessTag' => $this->generateAccessTag(
                                'createVirtualCard',
                                (new \DateTimeImmutable())->format('Y-m-d H:i'),
                                $walletUser->getTreezorUserId(),
                                $walletUser->getWalletCompany()->getWalletBankAccount()->getTreezorWalletId(),
                                $walletUser->getTreezorEmail()
                            ),
                            'userId'            => $walletUser->getTreezorUserId(),
                            'walletId'          => $walletUser->getWalletCompany()->getWalletBankAccount()->getTreezorWalletId(),
                            'permsGroup'        => getenv('TREEZOR_PERMS_GROUP'),
                            'cardPrint'         => getenv('TREEZOR_CARD_PRINT'),
                            'pin'               => $walletCard->getNewPin(),
                            'deliveryLastname'  => $walletCard->getDeliveryAddress()->getLastName(),
                            'deliveryFirstname' => $walletCard->getDeliveryAddress()->getFirstName(),
                            'deliveryAddress1'  => $walletCard->getDeliveryAddress()->getStreet(),
                            'deliveryCity'      => $walletCard->getDeliveryAddress()->getCity(),
                            'deliveryPostcode'  => $walletCard->getDeliveryAddress()->getPostalCode(),
                            'deliveryCountry'   => $walletCard->getDeliveryAddress()->getCountry()->getCode(),
                            'limitAtmWeek'      => WalletCard::MAX_LIMIT_ATM_WEEK,
                            'limitPaymentDay'   => 0,
                            'limitPaymentWeek'  => WalletCard::MAX_LIMIT_PAYMENT_WEEK,
                        ],
                        $optionals
                    ),
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf(
                    "BankApi : erreur lors de la création d'une carte bancaire pour le user %d",
                    $walletUser->getTreezorUserId()
                ),
                $e,
                'exception.wallet.bank_api.create_virtual_card_error'
            );
        }

        $treezorCards = json_decode($res->getBody()->getContents(), true);
        $treezorCard  = self::getOnlyElement($treezorCards['cards']);

        return self::convertTreezorCardToTypedArray($treezorCard);
    }

    /**
     * @throws WalletException
     */
    private function convertToPhysicalCard(string $virtualCardId, Address $address): array
    {
        try {
            $optionals = [];

            $title = TreezorCivility::toTreezor($address->getTitle());

            if (null !== $title) {
                $optionals['deliveryTitle'] = $title;
            }

            if (null !== $address->getAdditionalInformation1()) {
                $optionals['deliveryAddress2'] = $address->getAdditionalInformation1();
            }

            if (null !== $address->getAdditionalInformation2()) {
                $optionals['deliveryAddress3'] = $address->getAdditionalInformation2();
            }

            $res = $this->guzzleClient->request(
                Request::METHOD_PUT,
                'cards/' . $virtualCardId . '/ConvertVirtual/',
                [
                    'json' => array_merge(
                        [
                            'accessTag'         => $this->generateAccessTag('convertToPhysicalCard', $virtualCardId),
                            'deliveryLastname'  => $address->getLastName(),
                            'deliveryFirstname' => $address->getFirstName(),
                            'deliveryAddress1'  => $address->getStreet(),
                            'deliveryCity'      => $address->getCity(),
                            'deliveryPostcode'  => $address->getPostalCode(),
                            'deliveryCountry'   => $address->getCountry()->getCode(),
                        ],
                        $optionals
                    ),
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la conversion de la carte virtuelle avec l\'API Bancaire (%d)', $virtualCardId),
                $e,
                'exception.wallet.bank_api.convert_physical_card_error'
            );
        }

        $treezorCards = json_decode($res->getBody()->getContents(), true);
        $treezorCard  = self::getOnlyElement($treezorCards['cards']);

        return self::convertTreezorCardToTypedArray($treezorCard);
    }

    /**
     * @throws WalletException
     */
    public function updateCardOptions(WalletCard $walletCard): array
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_PUT,
                'cards/' . $walletCard->getTreezorCardId() . '/Options/',
                [
                    'json' => [
                        'foreign' => $walletCard->isForeign(),
                        'online'  => $walletCard->isOnline(),
                        'atm'     => $walletCard->isAtm(),
                        'nfc'     => $walletCard->isNfc(),
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la mise à jour des options de la carte %d', $walletCard->getId()),
                $e,
                'exception.wallet.bank_api.update_card_options_error'
            );
        }

        $treezorCards = json_decode($res->getBody()->getContents(), true);
        $treezorCard  = self::getOnlyElement($treezorCards['cards']);

        return self::convertTreezorCardToTypedArray($treezorCard);
    }

    /**
     * @throws WalletException
     */
    public function register3DSecure(WalletCard $walletCard): void
    {
        try {
            $this->guzzleClient->request(
                Request::METHOD_POST,
                'cards/Register3DS',
                [
                    'json' => [
                        'accessTag' => $this->generateAccessTag(
                            'register3DSecure',
                            $walletCard->getWalletUser()->getTreezorUserId(),
                            $walletCard->getTreezorCardId()
                        ),
                        'cardId' => $walletCard->getTreezorCardId(),
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de l\'activation 3DSecure de la carte %d', $walletCard->getId()),
                $e,
                'exception.wallet.bank_api.register_3dsecure_card_error'
            );
        }
    }

    private function generateAccessTag(...$elements): string
    {
        return md5(implode('', $elements));
    }

    public static function convertTreezorPayoutToTypedArray(array $treezorPayout): array
    {
        return [
            'user_id'        => (int) $treezorPayout['userId'],
            'payout_id'      => (int) $treezorPayout['payoutId'],
            'beneficiary_id' => (int) $treezorPayout['beneficiaryId'],
            'payout_type_id' => (int) $treezorPayout['payoutTypeId'],
            'wallet_id'      => (int) $treezorPayout['walletId'],
            'created_date'   => WalletHelper::getDateTimeFromTreezorDateTime($treezorPayout['createdDate']),
            'modified_date'  => true === isset($treezorPayout['modifiedDate']) ? WalletHelper::getDateTimeFromTreezorDateTime($treezorPayout['modifiedDate']) : null,
            'payout_date'    => WalletHelper::getDateTimeFromTreezorDate($treezorPayout['payoutDate']),
            'payout_status'  => EnumWalletTransferStatus::toWallet($treezorPayout['payoutStatus']),
            'amount'         => (float) $treezorPayout['amount'],
            'label'          => $treezorPayout['label'],
        ];
    }

    public static function convertTreezorBeneficiaryToTypedArray(array $treezorBeneficiary): array
    {
        return [
            'id'                                      => (int) $treezorBeneficiary['id'],
            'user_id'                                 => (int) $treezorBeneficiary['userId'],
            'name'                                    => $treezorBeneficiary['name'],
            'iban'                                    => $treezorBeneficiary['iban'],
            'bic'                                     => $treezorBeneficiary['bic'],
            'address'                                 => $treezorBeneficiary['address'],
            'type'                                    => $treezorBeneficiary['usableForSct'] ? EnumWalletBeneficiaryType::CREDITOR : EnumWalletBeneficiaryType::DEBTOR,
            'sepa_creditor_identifier'                => $treezorBeneficiary['sepaCreditorIdentifier'],
            'sdd_core_blacklist'                      => (array) $treezorBeneficiary['sddCoreBlacklist'],
            'sdd_core_known_unique_mandate_reference' => (array) $treezorBeneficiary['sddCoreKnownUniqueMandateReference'],
        ];
    }

    public static function convertTreezorDocumentToTypedArray(array $treezorDocument): array
    {
        return [
            'document_id'     => (int) $treezorDocument['documentId'],
            'file_name'       => $treezorDocument['fileName'],
            'document_status' => EnumWalletDocumentStatus::toWallet($treezorDocument['documentStatus']),
        ];
    }

    public static function convertTreezorCardTransactionToTypedArray(array $treezorCardTransaction): array
    {
        return [
            'cardtransaction_id'          => (int) $treezorCardTransaction['cardtransactionId'],
            'wallet_id'                   => (int) $treezorCardTransaction['walletId'],
            'card_id'                     => $treezorCardTransaction['cardId'],
            'authorization_issuer_time'   => WalletHelper::getDateTimeFromTreezorDateTime($treezorCardTransaction['authorizationIssuerTime']),
            'mcc_code'                    => $treezorCardTransaction['mccCode'],
            'merchant_name'               => $treezorCardTransaction['merchantName'],
            'merchant_country'            => $treezorCardTransaction['merchantCountry'],
            'payment_country'             => $treezorCardTransaction['paymentCountry'],
            'payment_id'                  => $treezorCardTransaction['paymentId'],
            'payment_status'              => EnumWalletCardTransactionStatus::toWallet($treezorCardTransaction['paymentStatus']),
            'payment_amount'              => (float) $treezorCardTransaction['paymentAmount'],
            'is_3ds'                      => (bool) $treezorCardTransaction['is3DS'],
            'total_payment_week'          => (float) $treezorCardTransaction['totalLimitPaymentWeek'],
            'total_atm_week'              => (float) $treezorCardTransaction['totalLimitAtmWeek'],
            'authorization_response_code' => $treezorCardTransaction['authorizationResponseCode'],
            'authorization_note'          => $treezorCardTransaction['authorizationNote'],
        ];
    }

    public static function convertTreezorPayinToTypedArray(array $treezorPayin): array
    {
        if (true === \array_key_exists('paymentMethodId', $treezorPayin) && (string) (TreezorPayinMethod::CHECK) === $treezorPayin['paymentMethodId']) {
            $additionalData               = json_decode($treezorPayin['additionalData'], true);
            $treezorPayin['ibanFullname'] = $treezorPayin['ibanFullname'] ?? '';

            return [
                'wallet_id'         => (int) $treezorPayin['walletId'],
                'payin_id'          => (int) $treezorPayin['payinId'],
                'amount'            => (float) $treezorPayin['amount'],
                'created_date'      => WalletHelper::getDateTimeFromTreezorDateTime($treezorPayin['createdDate']),
                'payment_method_id' => TreezorPayinMethod::toWallet($treezorPayin['paymentMethodId']),
                'message_to_user'   => $treezorPayin['messageToUser'],
                'payin_status'      => EnumDepositStatus::codeToStatus($treezorPayin['codeStatus']),
                'iban_fullname'     => $treezorPayin['ibanFullname'],
                'debtor_iban'       => $treezorPayin['DbtrIBAN'],
                'cmc7'              => $additionalData['cheque']['cmc7']['a'] . $additionalData['cheque']['cmc7']['b'] . $additionalData['cheque']['cmc7']['c'],
                'rlmc_key'          => $additionalData['cheque']['RLMCKey'],
                'firstname'         => $additionalData['cheque']['drawerData']['firstName'],
                'lastname'          => $additionalData['cheque']['drawerData']['lastName'],
                'drawer_type'       => EnumDrawerType::toWallet($additionalData['cheque']['drawerData']['isNaturalPerson']),
                'code_status'       => $treezorPayin['codeStatus'],
                'wording'           => 'Encaissement chèque',
            ];
        }

        return [
            'wallet_id'         => (int) $treezorPayin['walletId'],
            'payin_id'          => $treezorPayin['payinId'],
            'amount'            => (float) $treezorPayin['amount'],
            'created_date'      => WalletHelper::getDateTimeFromTreezorDateTime($treezorPayin['createdDate']),
            'payment_method_id' => TreezorPayinMethod::toWallet($treezorPayin['paymentMethodId']),
            'message_to_user'   => false === mb_strpos($treezorPayin['messageToUser'], ' - Creditor Name SEPA') ?
                $treezorPayin['messageToUser']
                : mb_substr($treezorPayin['messageToUser'], 0, mb_strpos($treezorPayin['messageToUser'], ' - Creditor Name SEPA')),
            'payin_status'  => EnumWalletTransferStatus::toWallet($treezorPayin['payinStatus']),
            'iban_fullname' => $treezorPayin['ibanFullname'],
            'debtor_iban'   => $treezorPayin['DbtrIBAN'],
        ];
    }

    public static function convertTreezorSepaSddrToTypedArray(array $treezorSepaSddr): array
    {
        return [
            'wallet_id'                   => (int) $treezorSepaSddr['wallet_id'],
            'transaction_id'              => $treezorSepaSddr['transaction_id'],
            'beneficiary_id'              => (int) $treezorSepaSddr['beneficiary_id'],
            'interbank_settlement_amount' => $treezorSepaSddr['interbank_settlement_amount'],
            'requested_collection_date'   => $treezorSepaSddr['requested_collection_date'],
            'creditor_name'               => $treezorSepaSddr['creditor_name'],
            'creditor_address'            => $treezorSepaSddr['creditor_address'],
            'debitor_name'                => $treezorSepaSddr['debitor_name'],
            'debitor_address'             => $treezorSepaSddr['debitor_address'],
            'reject_reason_code'          => TreezorRejectReasonCode::toWallet($treezorSepaSddr['reject_reason_code'] ?: $treezorSepaSddr['reason_code'] ?? null),
            'sepa_creditor_identifier'    => $treezorSepaSddr['sepa_creditor_identifier'],
            'unstructured_field'          => $treezorSepaSddr['unstructured_field'],
            'rum'                         => $treezorSepaSddr['mandate_id'],
        ];
    }

    public static function convertTreezorCardToTypedArray(array $treezorCard): array
    {
        return [
            'card_id'            => (int) $treezorCard['cardId'],
            'user_id'            => (int) $treezorCard['userId'],
            'wallet_id'          => (int) $treezorCard['walletId'],
            'public_token'       => $treezorCard['publicToken'],
            'status_code'        => EnumWalletCardStatus::toWallet($treezorCard['statusCode']),
            'is_live'            => EnumWalletCardActivationStatus::toWallet($treezorCard['isLive']),
            'embossed_name'      => $treezorCard['embossedName'],
            'masked_pan'         => $treezorCard['maskedPan'],
            'expiry_date'        => WalletHelper::getDateTimeFromTreezorDate($treezorCard['expiryDate']),
            'option_atm'         => (bool) $treezorCard['optionAtm'],
            'option_foreign'     => (bool) $treezorCard['optionForeign'],
            'option_nfc'         => (bool) $treezorCard['optionNfc'],
            'option_online'      => (bool) $treezorCard['optionOnline'],
            'pin_try_exceeded'   => (bool) $treezorCard['pinTryExceeds'],
            'limit_atm_week'     => $treezorCard['limitAtmWeek'],
            'limit_payment_week' => $treezorCard['limitPaymentWeek'],
            'card_design'        => EnumWalletCardDesign::toWallet($treezorCard['cardDesign']),
        ];
    }

    public static function convertTreezorPayoutRefundToTypedArray(array $treezorPayoutRefund): array
    {
        return [
            'id'                 => (int) $treezorPayoutRefund['id'],
            'payout_id'          => (int) $treezorPayoutRefund['payoutId'],
            'information_status' => EnumWalletTransferStatus::toWallet($treezorPayoutRefund['informationStatus']),
            'created_date'       => WalletHelper::getDateTimeFromTreezorDateTime($treezorPayoutRefund['createdDate']),
            'modified_date'      => true === isset($treezorPayoutRefund['modifiedDate']) ? WalletHelper::getDateTimeFromTreezorDateTime($treezorPayoutRefund['modifiedDate']) : null,
            'amount'             => (float) $treezorPayoutRefund['requestAmount'],
        ];
    }

    public static function convertTreezorPayinRefundToTypedArray(array $treezorPayinRefund): array
    {
        return [
            'payin_refund_id'     => (int) $treezorPayinRefund['payinrefundId'],
            'wallet_id'           => (int) $treezorPayinRefund['walletId'],
            'payin_id'            => (int) $treezorPayinRefund['payinId'],
            'payin_refund_status' => EnumWalletPayinRefundStatus::toWallet($treezorPayinRefund['payinrefundStatus']),
            'created_date'        => WalletHelper::getDateTimeFromTreezorDateTime($treezorPayinRefund['createdDate']),
            'modified_date'       => true === isset($treezorPayinRefund['modifiedDate']) ? WalletHelper::getDateTimeFromTreezorDateTime($treezorPayinRefund['modifiedDate']) : null,
            'amount'              => (float) $treezorPayinRefund['amount'],
            'reason_tms'          => $treezorPayinRefund['reasonTms'],
        ];
    }

    public function extractTreezorDataFromPush(array $push, string $class)
    {
        return $this->serializer->denormalize(self::getOnlyElement($push), $class);
    }

    public function getCard(int $treezorId): TreezorCard
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_GET,
                'cards/' . $treezorId
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la récupération de la carte %d avec l\'API bancaire', $treezorId),
                $e
            );
        }

        $treezorCards = json_decode($res->getBody()->getContents(), true);
        $treezorCard  = self::getOnlyElement($treezorCards['cards']);

        return $this->serializer->denormalize($treezorCard, TreezorCard::class);
    }

    /**
     * @throws WalletException
     */
    public function updateCardLimits(WalletCard $walletCard): array
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_PUT,
                'cards/' . $walletCard->getTreezorCardId() . '/Limits/',
                [
                    'json' => [
                        'limitAtmWeek'     => $walletCard->getLimitAtmWeek(),
                        'limitPaymentWeek' => $walletCard->getLimitPaymentWeek(),
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la mise à jour des plafonds de la carte %d', $walletCard->getId()),
                $e,
                'exception.wallet.bank_api.update_card_limits_error'
            );
        }

        $treezorCards = json_decode($res->getBody()->getContents(), true);
        $treezorCard  = self::getOnlyElement($treezorCards['cards']);

        return self::convertTreezorCardToTypedArray($treezorCard);
    }

    public function getWallet(int $treezorId): TreezorWallet
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_GET,
                'wallets/' . $treezorId,
                [
                    'json' => [
                        'origin' => 'OPERATOR',
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la récupération du Wallet %d avec l\'API bancaire', $treezorId),
                $e
            );
        }

        $wallets = json_decode($res->getBody()->getContents(), true);
        $wallet  = self::getOnlyElement($wallets['wallets']);

        return $this->serializer->denormalize($wallet, TreezorWallet::class);
    }

    public function closeWallet(int $treezorId): TreezorWallet
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_DELETE,
                'wallets/' . $treezorId,
                [
                    'json' => [
                        'origin' => 'OPERATOR',
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Erreur lors de la suppression du Wallet %d avec l\'API bancaire', $treezorId),
                $e
            );
        }

        $wallets = json_decode($res->getBody()->getContents(), true);
        $wallet  = self::getOnlyElement($wallets['wallets']);

        return $this->serializer->denormalize($wallet, TreezorWallet::class);
    }

    public function debitClientInvoice(SalesforceCompany $company, SalesforceCompany $tiimeCompany, Invoice $invoice): TreezorTransfer
    {
        try {
            $res = $this->guzzleClient->request(
                Request::METHOD_POST,
                'transfers',
                [
                    'json' => [
                        'accessTag' => $this->generateAccessTag(
                            sprintf('invoice_%d', $invoice->getId()),
                            (new \DateTime())->format('Y-m-d H:i')
                        ),
                        'walletId'            => (string) $company->getWalletCompany()->getWalletBankAccount()->getTreezorWalletId(),
                        'beneficiaryWalletId' => (string) $tiimeCompany->getWalletCompany()->getWalletBankAccount()->getTreezorWalletId(),
                        'amount'              => (string) $invoice->getTotalIncludingTaxes(),
                        'label'               => 'Frais bancaires',
                        'currency'            => EnumCurrency::EURO,
                        'transferTypeId'      => self::CLIENT_FEES,
                        'transferTag'         => sprintf('invoice_%d', $invoice->getId()),
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw new WalletException(
                $e->getCode(),
                sprintf('Impossible de prélever les frais pour la société %s', $company->getId()),
                $e,
                'exception.wallet.bank_api.create_transfer_error'
            );
        }

        $treezorTransfers = json_decode($res->getBody()->getContents(), true);
        $treezorTransfer  = self::getOnlyElement($treezorTransfers['transfers']);

        return $this->serializer->denormalize($treezorTransfer, TreezorTransfer::class);
    }

    public function findTransfers(array $queryParameters): array
    {
        $res = $this->guzzleClient->request(
            Request::METHOD_GET,
            'transfers',
            [
                'query' => $queryParameters,
            ]
        );

        $treezorTransfers = json_decode($res->getBody()->getContents(), true);

        return $treezorTransfers['transfers'];
    }

    public function getGuzzleClient(): Client
    {
        return $this->guzzleClient;
    }
}
