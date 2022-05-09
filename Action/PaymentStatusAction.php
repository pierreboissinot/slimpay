<?php
namespace Payum\Slimpay\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;
use Payum\Slimpay\Constants;
use Payum\Slimpay\Request\Api\GetPaymentHumanStatus;
use Payum\Slimpay\Request\Api\SyncPayment;
use Payum\Slimpay\Util\ResourceSerializer;

class PaymentStatusAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    /**
     * {@inheritDoc}
     *
     * @param GetPaymentHumanStatus $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if($model['payment']) {
            $this->gateway->execute(new SyncPayment($model));
            $payment = ResourceSerializer::unserializeResource($model['payment']);
            switch ($payment->getState()['executionStatus']) {
                case Constants::PAYMENT_STATUS_PROCESSING:
                case Constants::PAYMENT_STATUS_TO_PROCESS:
                    $request->markPending();
                    break;
                case Constants::PAYMENT_STATUS_PROCESSED:
                    $request->markCaptured();
                    break;
                case Constants::PAYMENT_STATUS_REJECTED:
                    $request->markFailed();
                    break;
                default:
                    $request->markUnknown();
            }
        } else {
            $request->markNew();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof GetStatusInterface &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
