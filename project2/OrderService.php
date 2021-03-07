<?php

namespace App\Services;

use App\Exceptions\OrderException;
use App\Http\Requests\CheckoutBaseRequest;
use App\Mail\OrderRefund;
use App\Mail\OrderShipped;
use App\Mail\TelehealthConsult;
use App\Models\Addon;
use App\Models\Client;
use App\Models\Discount;
use App\Models\DiscountResponse;
use App\Models\DiscountUses;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\Refund;
use App\Support\Checkout\CheckoutItem;
use App\Support\Checkout\CheckoutPackage;
use App\Support\Checkout\DiscountCalculator;
use App\Support\Checkout\ItemsCollection;
use App\Support\Checkout\PackagesCollection;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class OrderService extends Service
{
    protected Order $order;

    protected ItemsCollection $items;

    protected PackagesCollection $packages;

    protected Discount $discount;

    protected Invoice $invoice;

    protected ?DiscountResponse $discountResponse;

    protected ?DiscountUses $discountUses;

    protected CheckoutBaseRequest $request;

    public function setRequest(CheckoutBaseRequest $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function setItems(ItemsCollection $items): self
    {
        $this->items = $items;

        return $this;
    }

    public function setPackages(PackagesCollection $packages): self
    {
        $this->packages = $packages;

        return $this;
    }

    public function makeDiscount(CheckoutBaseRequest $request, $email): self
    {
        if (! $code = $request->input('discount.code')) {
            return $this;
        }

        try {
            $this->discount = Discount::firstWhereCode($code);
            $discountResponse = DiscountCalculator::discountedTotal(
                $this->discount,
                $request->items(),
                $request->packages(),
                $email,
            );

            if ($discountResponse) {
                $this->discountResponse = $discountResponse->success ? $discountResponse : null;
            }
        } catch (Exception $e) {
            slack($e)->persist();
        }

        return $this;
    }

    /**
     * Creating the order based on the selected product for a given (logged in or newly created client).
     *
     * @param Client $client
     * @param $payload
     * @return OrderService
     */
    public function makeOrder(Client $client, $payload): self
    {
        $this->order = tap(Order::create([
            'status' => Order::STATUS_CART,
            'client_id' => $client->id,
            'user_id' => $client->user_id,
            'token' => Str::random(64),
            'billing_address' => $payload['billing_address'],
            'shipping_address' => $payload['shipping_address'],
            'po_number' => null,
            'info' => null,
            'final_price' => isset($this->discountResponse) ? data_get($this->discountResponse, 'total', 0) : 0,
            'preferred_delivery_date' => $this->getPreferredDeliveryDate(data_get($payload, 'shipping_address.preferred_delivery_date')),
            'affiliate_id' => data_get($payload, 'affiliate_id'),
        ]), function (Order $order) {
            $order->update([
                'final_price' => isset($this->discountResponse) ? $this->discountResponse->total : $this->request->rawTotal(),
                'po_number' => Order::nextPoNumber(),
                'affiliate_payout' => $order->getAffiliatePayoutAmount(),
            ]);
        });

        if (isset($this->discountResponse)) {
            with(new DiscountUses([
                'discount_id' => $this->discount->id,
                'order_id' => $this->order->id,
                'client_id' => $this->order->client_id,
                'email' => $this->order->client->email,
                'input_price' => $this->discountResponse->subtotal,
                'output_price' => $this->discountResponse->total,
            ]), function (DiscountUses $uses) {
                $uses->save();
                $this->discountUses = $uses;
            });
        }

        $this->packages->each(function (CheckoutPackage $checkoutPackage) {
            $orderPackage = $checkoutPackage->intoActualOrderPackage();
            $orderPackage->order_id = $this->order->id;
            $orderPackage->save();

            $checkoutPackage->addonsCollection->intoActualAddon()
                ->each(function (Addon $addon) use ($orderPackage) {
                    $orderPackage->addons()->create(array_merge($addon->toArray(), [
                        'order_id' => $this->order->id,
                        'client_id' => $this->order->client_id,
                    ]));
                });
        });

        // Saving package items.
        $this->packages
            ->items()
            ->fillClient($this->order->client)
            ->fillOrder($this->order)
            ->each->save();

        $this->items->each(function (CheckoutItem $checkoutItem) {
            $item = $checkoutItem->intoActualItem();
            $item->order_id = $this->order->id;
            $item->client_id = $this->order->client_id;
            $item->po_number = Item::nextPoNumber();
            $item->save();

            $checkoutItem->addonsCollection->intoActualAddon()
                ->each(function (Addon $addon) use ($item) {
                    $item->addons()->create(array_merge($addon->toArray(), [
                        'order_id' => $this->order->id,
                        'client_id' => $this->order->client_id,
                    ]));
                });
        });

        return $this;
    }

    /**
     * Will generate a payment request for stripe
     * @return $this
     */
    public function preparePaymentProvider(): self
    {
        $order = $this->order->refresh();

        $order->update([
            'status' => Order::STATUS_PAYMENT_PROVIDER,
        ]);

        return $this;
    }

    public function applySubscription()
    {
        return $this;
    }

    /**
     * Generating the invoice based on order and discount
     *
     * @param $email
     * @return $this
     */
    public function invoice($email)
    {
        $order = $this->freshOrder();

        $packageInvoiceLines = $this
            ->packages
            ->intoActualOrderPackage()
            ->map(function (OrderPackage $orderPackage) {
                return InvoiceLine::create([
                    'invoice_id' => null,
                    'type' => InvoiceLine::TYPE_PACKAGE,
                    'item_id' => $orderPackage->package->id,
                    'name' => $orderPackage->package->name,
                    'sku' => "{$orderPackage->package->id}-" . time(),
                    'quantity' => $orderPackage->quantity,
                    'unit_price' => $orderPackage->package->price,
                    'total' => $orderPackage->package->price,
                ]);
            });

        $invoiceLines = $order->items
            ->reject(fn (Item $item) => $item->hasPackage())
            ->map(function (Item $item) use ($order) {
                return InvoiceLine::create([
                    'invoice_id' => null,
                    'item_id' => $item->id,
                    'name' => $item->title,
                    'sku' => $item->product->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->getUnitPrice(),
                    'total' => $item->getTotalPrice(),
                ]);
            })->merge($packageInvoiceLines);

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'client_id' => $order->client_id,
            'discount_uses_id' => isset($this->discountUses) ? optional($this->discountUses)->id : null,
            'email' => $email,
            'status' => Invoice::STATUS_DRAFT,
            'discount_savings' => isset($this->discountResponse) ? $this->discountResponse->subtotal - $this->discountResponse->total : null,
            'subtotal' => $order->total(),
            'total' => isset($this->discountResponse) ? $this->discountResponse->total : $order->total(),
            'shipping_address' => $order->shipping_address,
            'billing_address' => $order->billing_address,
        ]);

        $invoiceLines->each->update(['invoice_id' => $invoice->id]);

        $this->invoice = $invoice;

        return $this;
    }

    /**
     * Send an email with order details to the client
     *
     * @param $email
     * @param bool $showBiling
     * @return $this
     */
    public function notify($email, $showBiling = true): self
    {
        if (isset($this->discount) && $this->discount->code === 'SOMEDISCOUNT') {
            Mail::to($email)->send(new OrderShipped($this->order, $this->invoice, $email, false));
            $email = config('email_support');
        }

        Mail::send(new OrderShipped($this->order, $this->invoice, $email, $showBiling));


        $this->invoice->update(['status' => Invoice::STATUS_SENT,]);

        $this->order->update(['status' => Order::STATUS_EMAILED,]);

        $orderProducts = $this->order->items->map(function ($item) {
            return $item->product;
        });

        if ($orderProducts->firstWhere('uuid', '25965d7b-098b-4254-8135-00992a312fd7')) {
            Mail::to($email)->send(new TelehealthConsult($this->order->client));
        }

        return $this;
    }

    /**
     * @return Order
     */
    public function freshOrder()
    {
        return $this->order->refresh();
    }

    /**
     * Refund the order if it was paid
     *
     * @param null $amount $ dollars
     * @param null $reason
     * @param null $description
     * @return $this
     */
    public function refund($amount = null, $reason = null)
    {
        return DB::transaction(function () use ($amount, $reason) {
            if (! in_array($this->order->status, [Order::STATUS_PAID, Order::STATUS_EMAILED, Order::STATUS_REFUNDED])) {
                throw new OrderException(__('Invalid order status. Waiting for status "paid", given :status.', ['status' => $this->order->status,]));
            }

            $this->order->refund($amount);

            if ($this->order->final_price > 0) {
                Validator::make($this->order->toArray(), ['payment_reference' => 'required',])->validate();

                try {
                    // This usually throws silent
                    $this->order->user->refund($this->order->payment_reference, [
                        'amount' => $amount ? centify($amount) : null,
                        'reason' => $reason,
                    ]);
                    Refund::create([
                        'invoice_id' => $this->order->invoice->id,
                        'amount' => $amount,
                        'reason' => $reason,
                    ]);
                } catch (Throwable $e) {
                    // by throw this the DB transaction will revert the refund order status
                    throw new OrderException(__('Could not refund the order. Please try again.'), $e->getCode(), $e);
                }
            }

            if ($this->order->user->delegateBy()->exists()) {
                Mail::to($this->order->user->delegateBy->email)->send(new OrderRefund($this->order, $amount, true));
                Mail::to($this->order->user->email)->send(new OrderRefund($this->order, $amount, false));
            } else {
                Mail::to($this->order->user->email)->send(new OrderRefund($this->order, $amount));
            }

            return $this;
        });
    }

    /**
     * @param Order $order
     * @return $this
     */
    public static function withOrder(Order $order)
    {
        $service = resolve(static::class);

        $service->order = $order;

        return $service;
    }

    public function getPreferredDeliveryDate($preferredDate)
    {
        if ($preferredDate) {
            try {
                return $preferredDate !== 'empty' ? Carbon::parse($preferredDate) : null;
            } catch (\Exception $e) {
                return null;
            }
        }

        $clinicDaysOff = HelperService::getClinicDaysOff();
        $disabledDays = [0, 6]; // 0: Sunday 6: Saturday
        $currentDate = Carbon::now()->setTimezone('America/New_York');

        if ($currentDate->dayOfWeek === 0) { // if current day is Sunday
            $currentDate->addDays(3);
        } elseif ($currentDate->dayOfWeek === 6) { // if current day is Saturday
            $currentDate->addDays(4);
        } elseif ($currentDate->greaterThanOrEqualTo(Carbon::instance($currentDate)->setTimeFromTimeString('14:30:00'))) {
            // if current date is 4: Thursday / 5: Friday]
            in_array($currentDate->dayOfWeek, [4, 5]) ? $currentDate->addDays(5) : $currentDate->addDays(3);
        } else {
            in_array($currentDate->dayOfWeek, [4, 5]) ? $currentDate->addDays(4) : $currentDate->addDays(2);
        }

        while (! $preferredDate) {
            if (in_array($currentDate->dayOfWeek, $disabledDays) || in_array($currentDate->toDateString(), $clinicDaysOff)) {
                $currentDate->addDay();

                continue;
            }

            $preferredDate = $currentDate;
        }

        return $preferredDate;
    }

    public function getFinalPrice(): float
    {
        $order = new Order;

        return isset($this->discountResponse) ? $this->discountResponse->total : $order->total($this->items->intoActualItem(), $this->packages->intoActualPackage());
    }
}
