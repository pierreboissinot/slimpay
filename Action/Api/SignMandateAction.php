<?php

namespace Payum\Slimpay\Action\Api;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Slimpay\Constants;
use Payum\Slimpay\Request\Api\CheckoutIframe;
use Payum\Slimpay\Request\Api\CheckoutRedirect;
use Payum\Slimpay\Request\Api\SignMandate;
use Payum\Slimpay\Util\ResourceSerializer;

class SignMandateAction extends BaseApiAwareAction
{
    /**
     * {@inheritDoc}
     *
     * @param SignMandate $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (!in_array($model['payment_schema'], Constants::getSupportedPaymentShemas())) {
            throw new LogicException('Payment Schema not set or not supported');
        }

        if (Constants::PAYMENT_SCHEMA_CARD == $model['payment_schema']) {
            throw new LogicException('Mandate signing is not available for Card schema');
        }

        $model->validateNotEmpty([
            'subscriber_reference',
            'first_name',
            'last_name',
            'address1',
            'city',
            'zip',
            'country'
        ]);

        $model['order'] = ResourceSerializer::serializeResource(
            $this->api->signMandate($model['subscriber_reference'], $model['payment_schema'], [
                'givenName' => $model['first_name'],
                'familyName' => $model['last_name'],
                'email' => $model['email'],
                'telephone' => $model['phone'],
                'companyName' => $model['company'],
                'organizationName' => $model['organization'],
                'billingAddress' => [
                    'street1' => $model['address1'],
                    'street2' => $model['address2'],
                    'city' => $model['city'],
                    'postalCode' => $model['zip'],
                    'country' => $model['country']
                ]
            ])
        );

        if(Constants::CHECKOUT_MODE_REDIRECT == $model['checkout_mode']) {
            $this->gateway->execute(new CheckoutRedirect($model));
        } elseif (in_array(
            $model['checkout_mode'],
            [Constants::CHECKOUT_MODE_IFRAME_EMBADDED, Constants::CHECKOUT_MODE_IFRAME_POPIN]
        )) {
            $this->gateway->execute(new CheckoutIframe($model));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return $request instanceof SignMandate &&
            $request->getModel() instanceof \ArrayAccess;
    }
}