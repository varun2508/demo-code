<?php

namespace App\Http\Controllers\Checkout;

use App\Events\OrderReadyEvent;
use App\Events\SalesForceEvent;
use App\Exceptions\OrderException;
use App\Http\Controllers\ApiController;
use App\Http\Requests\CheckoutRequest;
use App\Models\Dto\SalesForce;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\ClientService;
use App\Services\HelperService;
use App\Services\OrderService;
use Binarcode\LaravelDeveloper\Models\ExceptionLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Exceptions\InvalidCustomer;
use Laravel\Cashier\Exceptions\PaymentActionRequired;
use Laravel\Cashier\Exceptions\PaymentFailure;
use Throwable;

class OrderController extends ApiController
{
    protected ClientService $clientService;

    protected OrderService $orderService;

    public function __construct(ClientService $clientService, OrderService $orderService)
    {
        $this->clientService = $clientService;
        $this->orderService = $orderService;
    }

    public function checkout(CheckoutRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $client = $this->clientService->firstOrCreate($request->clientPayload());

                $this->orderService
                    ->setRequest($request)
                    ->setPackages($request->packages())
                    ->setItems($request->items())
                    ->makeDiscount($request, $request->clientEmail())
                    ->makeOrder($client, $request->toArray())
                    ->preparePaymentProvider();

                $order = $this->orderService->freshOrder();

                if (in_array($order->status, [Order::STATUS_PAYMENT_PROVIDER]) === false) {
                    throw new OrderException(__('Invalid order status. Waiting for status "payment-provider", given :status.', ['status' => $order->status]));
                }

                if ($order->final_price <= 0) {
                    $order->setStatus(Order::STATUS_PAID);
                } else {
                    $request
                        ->paymentData()
                        ->resolvePaymentProvider()
                        ->pay($order, $request->paymentData());
                }

                $this->orderService->invoice($request->clientEmail());

                // Delegate Access
                if ($request->delegate && ! empty($request->delegate)) {
                    $delegatedUser = User::firstOrCreate(Arr::only($request->delegate, 'email'), array_merge([
                        'role_id' => Role::CLIENT,
                    ], $request->delegate));
                    $delegatedUser->delegated_by = $client->user->id;
                    $delegatedUser->is_delegated_active = true;
                    $delegatedUser->save();

                    // PLAT-99
                    if ($request->input('discount.code') !== config('milkstork_discount_code')) {
                        $this->orderService->notify($request->clientEmail(), false);
                    }

                    $this->orderService->notify($delegatedUser->email);
                } else {
                    $this->orderService->notify($request->clientEmail());
                }
            });

            if ($fresh = $this->orderService->freshOrder()) {
                event(new OrderReadyEvent($fresh->load('user')));
                event(new SalesForceEvent(SalesForce::makeFromRequest($request)->setEventType(SalesForce::ACTION_ORDER_CREATED)));

                return $this->response()->data($fresh);
            }
        } catch (PaymentActionRequired | PaymentFailure $e) {
            ExceptionLog::makeFromException($e, $e->payment->asStripePaymentIntent())->notifyDevs();

            return $this->response()
                ->invalid()
                ->errors($e->getMessage())
                ->respond();
        } catch (OrderException | InvalidCustomer $e) {
            ExceptionLog::makeFromException($e)->notifyDevs();

            return $this->response()
                ->invalid()
                ->errors($e->getMessage())
                ->respond();
        } catch (Throwable $e) {
            if (App::environment('local') || App::environment('testing')) {
                dd($e);
            }

            ExceptionLog::makeFromException($e)->notifyDevs();

            if ($request->paymentData()->getProvider() === Order::PAYMENT_SERVICE_SPLITIT) {
                $request->paymentData()->resolvePaymentProvider()->cancelInstallment($request->paymentData());
            }

            return $this->response()
                ->invalid()
                ->errors(__('Something went wrong on our end, please contact support for help.'))
                ->respond();
        }
    }

    public function getClinicDaysOff()
    {
        return HelperService::getClinicDaysOff();
    }

    public function resolvePaymentProvider(array $service)
    {
    }
}
