<?php

namespace App\Models;

use App\CollectionMacros\Kits;
use App\Events\OrderApproved;
use App\Models\Concerns\Approvable;
use App\Models\Concerns\HasAddress;
use App\Models\Concerns\RevampConnection;
use App\Models\Concerns\WithBuilders;
use App\Models\Concerns\WithCryostorage;
use App\Models\Contracts\HasMails;
use App\Models\Dto\Clinic;
use App\Models\Dto\SomeClinic;
use App\Models\Enum\Steps;
use App\Support\Concerns\ShippedByMainfreight;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Actionable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\SchemalessAttributes\SchemalessAttributes;

/**
 * Class Order
 * @property int id
 * @property int transfer_id
 * @property string uuid
 * @property string status
 * @property int step
 * @property string shipping_status
 * @property int client_id
 * @property int user_id
 * @property int square_space_id
 * @property int affiliate_id
 * @property float final_price
 * @property float refund_amount
 * @property float affiliate_payout
 * @property string payment_service
 * @property bool auto
 * @property bool vip
 * @property string payment_reference
 * @property int number_of_kits
 * @property string po_number // used in the shipped email
 * @property string clinic
 * @property Carbon preferred_delivery_date
 * @property Carbon preferred_delivery_date_confirmed_at
 * @property bool confirmed_delivery_date
 * @property SchemalessAttributes billing_address
 * @property SchemalessAttributes shipping_address
 * @property string notes
 * @property string considering_freezing
 * @property Carbon created_at
 * @property Carbon updated_at
 * @property Carbon submitted_at
 * @property Carbon submitted_lifestyle_at
 * @property bool has_lifestyle
 * @property Carbon approved_at
 * @property Carbon completed_at
 * @property bool approved
 * @property bool completed
 * @property Carbon refunded_at
 * @property Carbon cancelled_at
 * @property Carbon imported_from_admin_at
 * @property Carbon exported_to_admin_at
 * @property-read string coupon
 * @property string type
 * @property string advisor_email
 * @property string shippment_week_day
 *
 * @property-read DiscountUses discountUses
 * @property Collection $items
 * @property Collection $packages
 * @property User $user
 * @property Invoice $invoice
 * @property Client $client
 * @property Collection $trackingNumbers
 *
 * @method static Builder|Order number(int $number)
 * @method static Builder|Order readyForShipping()
 * @method static Order whereUuid(string $uuid)
 * @package App\Models
 */
class Order extends Model implements HasMedia, HasMails
{
    use WithUuid,
        Actionable,
        AddressTrait,
        HasFactory,
        InteractsWithMedia,
        ShippedByMainfreight,
        RevampConnection,
        WithBuilders,
        WithCryostorage,
        HasAddress,
        Approvable;

    const STATUS_CART = 'cart';
    const STATUS_PAYMENT_PROVIDER = 'payment-provider';
    const STATUS_PAID = 'paid';
    const STATUS_EMAILED = 'emailed'; //successfully sent to the client after paid
    const STATUS_EXPIRED = 'expired';
    const STATUS_REJECTED = 'rejected';
    const STATUS_REFUNDED = 'refunded';

    const STATUS_TYPES = [
        self::STATUS_CART => 'Cart',
        self::STATUS_PAYMENT_PROVIDER => 'Payment Provider',
        self::STATUS_PAID => 'Paid',
        self::STATUS_EMAILED => 'Confirmation email sent to the client',
        self::STATUS_EXPIRED => 'Expired',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_REFUNDED => 'Refunded',
    ];

    const SHIPPING_STATUS_READY = 'ready-for-shipping';
    const SHIPPING_STATUS_SENT = 'shipping-details-sent';
    const SHIPPING_STATUS_SHIPPED = 'shipped';

    const SHIPPING_STATUSES = [
        self::SHIPPING_STATUS_READY => 'Ready',
        self::SHIPPING_STATUS_SENT => 'Sent',
        self::SHIPPING_STATUS_SHIPPED => 'Shipped',
    ];

    const PAYMENT_SERVICE_STRIPE = 'stripe';
    const PAYMENT_SERVICE_SPLITIT = 'splitit';

    const PAYMENT_SERVICES = [
        self::PAYMENT_SERVICE_STRIPE => 'Stripe',
        self::PAYMENT_SERVICE_SPLITIT => 'SplitIt',
    ];

    protected static function boot()
    {
        parent::boot();
    }

    protected $table = 'orders';

    protected $fillable = [
        'uuid',
        'transfer_id',
        'status',
        'shipping_status',
        'client_id',
        'user_id',
        'square_space_id',
        'affiliate_id',
        'final_price',
        'refund_amount',
        'affiliate_payout',
        'auto',
        'payment_reference',
        'po_number',
        'clinic',
        'preferred_delivery_date',
        'billing_address',
        'shipping_address',
        'notes',
        'preferred_delivery_date_confirmed_at',
        'submitted_lifestyle_at',
        'submitted_at',
        'approved_at',
        'refunded_at',
        'cancelled_at',
        'completed_at',
        'imported_from_admin_at',
        'exported_to_admin_at',
        'payment_service',
    ];

    /**
     * @var array
     */
    protected $hidden = [
        'id',
        'client_id',
        'user_id',
        'square_space_id',
    ];

    protected $casts = [
        'client_id' => 'int',
        'auto' => 'boolean',
        'vip' => 'boolean',
        'shipping_address' => 'json',
        'billing_address' => 'json',
        'preferred_delivery_date' => 'date:m/d/Y',
        'final_price' => 'float',
        'refund_amount' => 'float',
        'affiliate_payout' => 'float',
        'preferred_delivery_date_confirmed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'refunded_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'imported_from_admin_at' => 'datetime',
        'exported_to_admin_at' => 'datetime',
        'submitted_lifestyle_at' => 'datetime',
        'created_at' => 'datetime',
        'step' => 'integer',
        'po_number' => 'integer',
        'with_dna_fragmentation' => 'boolean',
        'number_of_kits' => 'integer',
    ];

    protected $appends = [
        'human_delivery_date',
        'human_delivery_address',
    ];

    protected $attributes = [
        'shipping_status' => self::SHIPPING_STATUS_READY,
    ];

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function total(Collection $items = null, Collection $packages = null): float
    {
        $items = $items ?? $this->items;

        $items = $items->filter(fn (Item $item) => is_null($item->package_id));

        $packages = ($packages ?? $this->packages) ?? collect();

        $itemsTotal = (float)$items->reduce(function ($result, $item) {
            $addonPrice = $item->addons->reduce(fn ($price, Addon $addon) => $price + $addon->price,  0);

            return $result + (data_get($item, 'quantity') * data_get($item, 'price')) + $addonPrice;
        }, 0);

        $packagesTotal = (float)$packages->reduce(function ($result, $orderPackage) {
            return $result + $orderPackage->price * $orderPackage->pivot->quantity;
        }, 0);


        return (float)$packagesTotal + $itemsTotal;
    }

    /**
     * Returns the sum of the order items multiplied by quantity
     *
     * @param Collection $items
     * @return float
     */
    public static function itemsTotal(Collection $items): float
    {
        return (new static)->total($items);
    }

    /**
     * @return string|null
     */
    public function getShippingPhone()
    {
        if ($this->shipping_address) {
            return "{$this->shipping_address['phone']}";
        }

        return null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function fulfillment()
    {
        return $this->hasOne(Fulfillment::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * Mark order and associated invoice refunded
     *
     * @return $this
     */
    public function refund($amount): self
    {
        $this->update([
            'status' => static::STATUS_REFUNDED,
            'refund_amount' => $amount,
        ]);

        $this->invoice->update([
            'status' => Invoice::STATUS_REFUNDED,
        ]);

        return $this;
    }

    /**
     * Check if order was paid
     *
     * @return bool
     */
    public function isPaid()
    {
        return in_array($this->status, [static::STATUS_PAID, static::STATUS_EMAILED]);
    }

    /**
     * @return bool
     */
    public function isNotPaid()
    {
        return ! $this->isPaid();
    }

    public function getHumanDeliveryDateAttribute()
    {
        //@todo adding logic for the specific not working days
        return $this->preferred_delivery_date ? $this->preferred_delivery_date->isoFormat('LL') : $this->getDefaultDeliveryDate()->isoFormat('LL');
    }

    public function getHumanDeliveryAddressAttribute()
    {
        return $this->humanizeAddress($this->shipping_address);
    }

    /**
     * Check if suck order belongs to given client
     *
     * @param Client $client
     * @return mixed
     */
    public function belongsToClient(Client $client)
    {
        return $client->orders->contains('uuid', $this->uuid);
    }

    public function getShippingAddressAttribute()
    {
        return SchemalessAttributes::createForModel($this, 'shipping_address');
    }

    public function getBillingAddressAttribute()
    {
        return SchemalessAttributes::createForModel($this, 'billing_address');
    }

    public static function nextPoNumber()
    {
        $last = static::query()->latest('po_number')->first();

        return $last ? $last->po_number + 3 : 19480;
    }

    public function scopeNumber($query, string $number)
    {
        $query->where('po_number', $number);
    }

    public function hasWithSubscriptions(): bool
    {
        return $this->items->some(fn (Item $item) => $item->product->plan ? true : false);
    }

    public function doesntHaveSubscriptions(): bool
    {
        return $this->hasWithSubscriptions() === false;
    }

    public function hasWithCryogenic(): bool
    {
        return $this->items->some(fn (Item $item) => $item->product->is_cryogenic);
    }

    public function doesntHaveCryogenic(): bool
    {
        return $this->hasWithCryogenic() === false;
    }

    public static function deliverable(Client $client)
    {
        return $client->orders
            ->filter(fn (Order $order) => $order->doesntHaveSubscriptions())
            ->filter(fn (Order $order) => $order->doesntHaveCryogenic());
    }

    public function discountUses()
    {
        return $this->hasMany(DiscountUses::class);
    }

    public function submittedLifestyle()
    {
        $this->submitted_lifestyle_at = now();
        $this->save();
    }

    public function getDefaultDeliveryDate()
    {
        return $this->created_at->addDay();
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class, 'affiliate_id');
    }

    public function getAffiliatePayoutAmount()
    {
        if ($this->affiliate && $this->final_price > 0) {
            if ($this->affiliate->payout_type === Affiliate::PAYOUT_PERCENT) {
                return round(($this->affiliate->payout_value / 100) * $this->final_price, 2);
            }

            return $this->affiliate->payout_value;
        }

        return 0;
    }

    public function getApprovedAttribute()
    {
        return ! is_null($this->approved_at);
    }

    public function getCompletedAttribute()
    {
        return ! is_null($this->completed_at);
    }

    public function getCouponAttribute()
    {
        return $this->discountUses->count()
            ? $this->discountUses->first()->discount->code
            : null;
    }

    public function getConfirmedDeliveryDateAttribute()
    {
        return ! is_null($this->preferred_delivery_date_confirmed_at);
    }

    public function getTypeAttribute()
    {
        if (! $item = $this->items()->first()) {
            return null;
        }

        return optional($item->product)->title;
    }

    public function getHasLifestyleAttribute()
    {
        return ! is_null($this->submitted_lifestyle_at);
    }

    public function complete()
    {
        $this->completed_at = now();
        $this->save();


        event(
            new OrderApproved($this)
        );

        return $this;
    }

    public function isApproved(): bool
    {
        return ! $this->isntApproved();
    }

    public function isntApproved(): bool
    {
        return is_null($this->approved_at);
    }

    public function setClinic(?string $clinic): self
    {
        $this->clinic = $clinic;
        $this->save();

        return $this;
    }

    public function setShippingStatus(?string $status): self
    {
        $this->shipping_status = $status;
        $this->save();

        return $this;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        $this->save();

        return $this;
    }

    public function setSuccessPaymentData(string $paymentService, string $paymentReference): self
    {
        $this->status = self::STATUS_PAID;
        $this->payment_service = $paymentService;
        $this->payment_reference = $paymentReference;
        $this->save();

        return $this;
    }

    public function hasPreferredDD(): bool
    {
        return ! is_null($this->preferred_delivery_date) && $this->preferred_delivery_date_confirmed_at;
    }

    public function isAutomatic(): bool
    {
        return $this->auto;
    }

    public function mails()
    {
        return $this->hasMany(Mail::class);
    }

    public function getAdvisorEmailAttribute(): string
    {
        return config('client_service_inbox');
    }

    public function getShippmentWeekDayAttribute(): string
    {
        $created = Carbon::parse($this->created_at)->timezone('America/Toronto');

        $isAfterWednesday = $created->dayOfWeek > 3;

        return $isAfterWednesday
            ? 'Monday'
            : $created->addDay()->format('l');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents');
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function resultDocuments()
    {
        return $this->hasMany(ResultDocument::class);
    }

    public function event()
    {
        return $this->morphOne(CalendarEvent::class, 'eventable');
    }

    public function trackingNumbers()
    {
        return $this->hasMany(TrackingNumber::class);
    }

    public function getTrackingLabel(): ?string
    {
        return optional($this->trackingNumbers()->shippo()->first())->label_url;
    }

    public function getTrackingReturnLabel(): ?string
    {
        return optional($this->trackingNumbers()->shippo()->first())->return_label_url;
    }

    public function isInUnitedStates(): bool
    {
        return $this->shipping_address->get('country') === 'US';
    }

    public function containPOBOX(): bool
    {
        if (empty($address = $this->shipping_address->get('address_1'))) {
            return false;
        }

        return Str::contains(
            Str::lower($address),
            Str::lower('PO Box')
        );
    }

    public function hasTodayPackage(): bool
    {
        return $this->items->some(fn (Item $item) => $item->isToday());
    }

    public function hasTomorrowPackage(): bool
    {
        return $this->items->some(fn (Item $item) => $item->isTomorrow());
    }

    public function canBeHandledByClinic(): bool
    {
        $threshold = (int)optional(Setting::firstWhere('name', 'clinic_threshold'))->value ?? config('clinic_threshold');

        return static::query()
                ->approvedForClinic(SomeClinic::CODE, $this->preferred_delivery_date)
                ->count() <= $threshold;
    }

    public function makeVip()
    {
        $this->vip = true;
        $this->save();

        return $this;
    }
s
    public function clientAggreement(): ?Document
    {
        return $this->documents()->where('step', Steps::CLIENT_AGREEMENT)->first();
    }

    public function agrementSigned(): bool
    {
        return (bool)optional($this->clientAggreement())->isSigned();
    }

    public function setStep(int $step): self
    {
        $this->step = $step;
        $this->save();

        return $this;
    }

    public function getTitleAttribute()
    {
        return $this->type
            ? $this->type . "($" . $this->final_price . ")"
            : $this->id;
    }

    public function currentHasResults(): bool
    {
        return $this->items->some(fn (Item $item) => $item->resultDocuments()->count() > 0);
    }

    public function hasOnlyCryogenic(): bool
    {
        $ids = Product::where('is_cryogenic', true)->pluck('id');

        return $this->items()->whereIn('product_id', $ids)->count() === $this->items()->count();
    }

    public function getClinicName(): ?string
    {
        if ($this->clinic) {
            return (Clinic::find($this->clinic))::NAME;
        }

        return $this->clinic;
    }

    public function setConsideringFreezing(string $considering): self
    {
        $this->considering_freezing = $considering;
        $this->save();

        return $this;
    }

    public function deliveryType(): ?string
    {
        return collect(Kits::deliveryTypes)->get(
            $this->shipping_address->get('kit_delivery_type'),
            'N/A'
        );
    }

    public function getWithDnaFragmentationAttribute()
    {
        return $this->items->some(fn (Item $item) => $item->hasDnaFragmentation());
    }

    public function packages()
    {
        return $this->belongsToMany(Package::class)->using(OrderPackage::class)->withPivot('quantity');
    }

    public function addons()
    {
        return $this->hasMany(Addon::class);
    }
}
