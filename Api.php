<?php
namespace Payum\Slimpay;

use HapiClient\Hal\Resource;
use HapiClient\Http\Follow;
use HapiClient\Http\JsonBody;
use Http\Message\MessageFactory;
use Payum\Core\HttpClientInterface;
use HapiClient\Http\HapiClient;
use HapiClient\Http\Auth\Oauth2BasicAuthentication;
use HapiClient\Hal\CustomRel;

class Api
{
    /**
     * @var HttpClientInterface
     */
    protected $client;

    /**
     * @var MessageFactory
     */
    protected $messageFactory;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var HapiClient
     */
    protected $hapiClient;

    /**
     * @param array               $options
     * @param HttpClientInterface $client
     * @param MessageFactory      $messageFactory
     *
     * @throws \Payum\Core\Exception\InvalidArgumentException if an option is invalid
     */
    public function __construct(array $options, HttpClientInterface $client, MessageFactory $messageFactory)
    {
        $this->options = $options;
        $this->client = $client;
        $this->messageFactory = $messageFactory;
        $this->hapiClient = new HapiClient(
            $this->getApiEndpoint(),
            '/',
            $this->getApiEndpoint() . '/alps/v1',
            new Oauth2BasicAuthentication(
                '/oauth/token',
                $options['app_id'],
                $options['app_secret']
            )
        );
    }

    /**
     * @param string $subscriberReference
     * @param string $paymentSchema
     * @param array $mandateFields
     *
     * @return Resource
     */
    public function signMandate($subscriberReference, $paymentSchema, array $mandateFields)
    {
        $fields = [
            'started' => true,
            'locale' => null,
            'paymentScheme' => $paymentSchema,
            'creditor' => [
                'reference' => $this->options['creditor_reference']
            ],
            'subscriber' => [
                'reference' => $subscriberReference
            ],
            'items' => [
                [
                    'type' => Constants::ITEM_TYPE_SIGN_MANDATE,
                    'action' => Constants::ITEM_ACTION_SIGN,
                    'mandate' => [
                        'reference' => null,
                        'signatory' => $mandateFields
                    ]
                ]
            ]
        ];

        return $this->doRequest('POST', Constants::FOLLOW_CREATE_ORDERS, $fields);
    }

    /**
     * @param string $subscriberReference
     * @param string $mandateReference
     *
     * @return Resource
     */
    public function updatePaymentMethodWithCheckout($subscriberReference, $mandateReference)
    {
        $fields = [
            'started' => true,
            'locale' => null,
            'creditor' => [
                'reference' => $this->options['creditor_reference']
            ],
            'subscriber' => [
                'reference' => $subscriberReference
            ],
            'items' => [
                [
                    'type' => Constants::ITEM_TYPE_SIGN_MANDATE,
                    'action' => Constants::ITEM_ACTION_AMEND_BANK_ACCOUNT,
                    'mandate' => [
                        'reference' => $mandateReference
                    ]
                ]
            ]
        ];

        return $this->doRequest('POST', Constants::FOLLOW_CREATE_ORDERS, $fields);
    }

    /**
     * @param string $mandateReference
     * @param string $iban
     *
     * @return Resource
     */
    public function updatePaymentMethodWithIban($mandateReference, $iban)
    {
        $mandate = $this->doRequest('GET', Constants::FOLLOW_GET_MANDATES, [
            'creditorReference' => $this->options['creditor_reference'],
            'reference' => $mandateReference
        ]);

        return $this->doRequest('POST', Constants::FOLLOW_UPDATE_BANK_ACCOUNT, [
            'iban' => $iban
        ], $mandate);
    }

    /**
     * @param string $subscriberReference
     *
     * @return Resource
     */
    public function setUpCardAlias($subscriberReference)
    {
        $fields = [
            'started' => true,
            'locale' => null,
            'paymentScheme' => Constants::PAYMENT_SCHEMA_CARD,
            'creditor' => [
                'reference' => $this->options['creditor_reference']
            ],
            'subscriber' => [
                'reference' => $subscriberReference
            ],
            'items' => [
                [
                    'type' => Constants::ITEM_TYPE_CARD_ALIAS,
                ]
            ]
        ];

        return $this->doRequest('POST', Constants::FOLLOW_CREATE_ORDERS, $fields);
    }

    /**
     * @param string $paymentReference
     * @param string $paymentSchema
     * @param array $fields
     *
     * @return Resource
     */
    public function createPayment($paymentReference, $paymentSchema, array $fields)
    {
        $fields['creditor'] = ['reference' => $this->options['creditor_reference']];

        if (Constants::PAYMENT_SCHEMA_CARD == $paymentSchema) {
            $fields[Constants::ITEM_TYPE_CARD_ALIAS] = [
                'reference' => $paymentReference
            ];
        } else {
            $fields[Constants::ITEM_TYPE_MANDATE] = [
                'reference' => $paymentReference
            ];
        }

        return $this->doRequest('POST', Constants::FOLLOW_CREATE_PAYINS, $fields);
    }


    /**
     * @param string $paymentScheme
     * @param string $mandateReference
     * @param array $fields
     *
     * @return Resource
     */
    public function refundPayment($paymentScheme, $mandateReference, array $fields)
    {
        $fields['creditor'] = ['reference' => $this->options['creditor_reference']];
        $fields['mandate'] = ['reference' => $mandateReference];
        $fields['scheme'] = $paymentScheme;

        return $this->doRequest('POST', Constants::FOLLOW_CREATE_PAYOUTS, $fields);
    }

    /**
     * @return string
     */
    public function getDefaultCheckoutMode()
    {
        return $this->options['default_checkout_mode'];
    }

    /**
     * @param Resource $order
     * @param string $iframeMode
     *
     * @return string
     */
    public function getCheckoutIframe(Resource $order, $iframeMode)
    {
        $resource = $this->doRequest(
            'GET',
            Constants::FOLLOW_EXTENDED_USER_APPROVAL,
            ['mode' => $iframeMode],
            $order
        );

        $html = $resource->getState()['content'];

        return base64_decode($html);
    }

    /**
     * @param Resource $order
     *
     * @return string
     */
    public function getCheckoutRedirect(Resource $order)
    {
        return $order->getLink($this->getRelationsNamespace() . Constants::FOLLOW_USER_APPROVAL)->getHref();
    }

    /**
     * @param Resource $order
     *
     * @return Resource
     */
    public function getOrderMandate(Resource $order)
    {
        return $this->doRequest('GET', Constants::FOLLOW_GET_MANDATE, null, $order);
    }

    /**
     * @param string $method
     * @param string $follow
     * @param array|null $fields
     * @param Resource|null $resource
     *
     * @return Resource
     */
    protected function doRequest($method, $follow, array $fields = null, Resource $resource = null)
    {
        $rel = new CustomRel($this->getRelationsNamespace() . $follow);

        $follow = new Follow($rel, $method, null, $fields ? new JsonBody($fields) : null);

        return $this->hapiClient->sendFollow($follow, $resource);
    }

    /**
     * @return string
     */
    protected function getRelationsNamespace()
    {
        return $this->getApiEndpoint() . '/alps#';
    }

    /**
     * @return string
     */
    protected function getApiEndpoint()
    {
        return $this->options['sandbox'] ?
            Constants::BASE_URI_SANDBOX :
            Constants::BASE_URI_PROD
        ;
    }
}
