<?php

namespace App\Models;

use App\CollectionMacros\Kits;
use App\Models\Concerns\Approvable;
use App\Models\Concerns\WithAddons;
use App\Models\Concerns\WithBuilders;
use App\Models\Concerns\WithCryostorage;
use App\Models\Contracts\HasMails;
use App\Models\Dto\SomeClinic;
use App\Support\Concerns\ShippedByMainfreight;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\SchemalessAttributes\SchemalessAttributes;

/**
 * Class Item
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property int $package_id
 * @property int $order_id
 * @property int $client_id
 * @property float $price
 * @property int $quantity
 * @property int $number_of_kits
 * @property int $po_number
 * @property string $clinic
 * @property string $notes
 * @property string $considering_freezing
 * @property string $shipping_status
 * @property string $with_dna_fragmentation
 * @property Carbon $approved_at
 * @property Carbon $preferred_delivery_date
 * @property Carbon $preferred_delivery_date_confirmed_at
 * @property SchemalessAttributes $billing_address
 * @property SchemalessAttributes $shipping_address
 * @property-read Product $product
 * @property-read Order $order
 * @method static Builder|Order number(int $number)
 * @method static Builder|Product isAnalysis()
 * @method static Builder|Order readyForShipping()
 * @package App\Models
 */
class Item extends Model implements HasMails
{
    use WithUuid,
        ShippedByMainfreight,
        AddressTrait,
        WithCryostorage,
        WithBuilders,
        Approvable,
        WithAddons;

    protected $table = 'items';

    const SHIPPING_STATUS_READY = 'ready-for-shipping';
    const SHIPPING_STATUS_SENT = 'shipping-details-sent';
    const SHIPPING_STATUS_SHIPPED = 'shipped';

    const SHIPPING_STATUSES = [
        self::SHIPPING_STATUS_READY => 'Ready',
        self::SHIPPING_STATUS_SENT => 'Sent',
        self::SHIPPING_STATUS_SHIPPED => 'Shipped',
    ];

    const CONSIDERING_FREEZING_YES = 'yes'; // 'Yes, likely if the sample is good'
    const CONSIDERING_FREEZING_NO = 'no'; // 'No, just here to test'
    const CONSIDERING_FREEZING_NOT_SURE = 'not_sure'; // 'I’m not sure yet, it depends'

    protected $guarded = [
        'addons',
    ];

    protected $fillable = [
        'product_id',
        'package_id',
        'order_id',
        'client_id',
        'title',
        'price',
        'quantity',
        'number_of_kits',
        'po_number',
        'clinic',
        'approved_at',
        'shipping_address',
        'notes',
        'considering_freezing',
        'shipping_status',
        'preferred_delivery_date',
        'preferred_delivery_date_confirmed_at',
    ];

    protected $casts = [
        'price' => 'float',
        'quantity' => 'integer',
        'approved_at' => 'datetime',
        'preferred_delivery_date' => 'date:m/d/Y',
        'preferred_delivery_date_confirmed_at' => 'datetime',
        'shipping_address' => 'array',
        'billing_address' => 'array',
    ];

    protected $hidden = [
        'id',
        'product_id',
        'order_id',
    ];

    protected $appends = [
        'human_delivery_date',
        'human_delivery_address',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function subscribeFor(User $user)
    {
        if ($plan = $this->product->plan) {
            $user->createSubscription($this->product->slug, $this->product->plan);

            return true;
        }

        return false;
    }

    public function getHumanDeliveryDateAttribute()
    {
        //@todo adding logic for the specific not working days
        return $this->preferred_delivery_date ? $this->preferred_delivery_date->isoFormat('LL') : $this->getDefaultDeliveryDate()->isoFormat('LL');
    }

    public function getDefaultDeliveryDate(): CarbonInterface
    {
        return optional($this->created_at)->addDay() ?? now()->addDay();
    }

    public function getHumanDeliveryAddressAttribute()
    {
        return $this->humanizeAddress($this->shipping_address);
    }

    public function isToday(): bool
    {
        return $this->product->sku === Kits::TODAY;
    }

    public function isTomorrow(): bool
    {
        return $this->product->sku === Kits::TOMORROW;
    }

    public function isForever(): bool
    {
        return $this->product->sku === Kits::FOREVER;
    }

    public function hasDnaFragmentation(): bool
    {
        return $this->addons->some(fn (Addon $addon) => $addon->isDNAFragmentation());
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public static function nextPoNumber()
    {
        $last = static::query()
            ->whereNotNull('po_number')
            ->latest('po_number')->first();

        return $last
            ? $last->po_number + 1
            : (Order::query()->latest('po_number')->first()->po_number + ($offset = 10000000));
    }

    public function getApprovedAttribute()
    {
        return ! is_null($this->approved_at);
    }

    public function isApproved(): bool
    {
        return ! $this->isntApproved();
    }

    public function isntApproved(): bool
    {
        return is_null($this->approved_at);
    }

    public function getShippingAddressAttribute()
    {
        return SchemalessAttributes::createForModel($this, 'shipping_address');
    }

    public function getBillingAddressAttribute()
    {
        return SchemalessAttributes::createForModel($this, 'billing_address');
    }

    public function getConfirmedDeliveryDateAttribute()
    {
        return ! is_null($this->preferred_delivery_date_confirmed_at);
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

    public function isKit(): bool
    {
        return in_array($this->product->sku, Product::KITS);
    }

    public function fulfillment()
    {
        return $this->hasOne(Fulfillment::class);
    }

    public function trackingNumbers()
    {
        return $this->hasMany(TrackingNumber::class);
    }

    public function mails()
    {
        return $this->hasMany(Mail::class);
    }

    public function setConsideringFreezing(string $considering): self
    {
        $this->considering_freezing = $considering;
        $this->save();

        return $this;
    }

    public function isAnalysisOnly(): bool
    {
        return $this->considering_freezing == static::CONSIDERING_FREEZING_NO;
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

    public function canBeHandledByClinic(): bool
    {
        return static::query()
            ->approvedForClinic(SomeClinic::CODE, $this->preferred_delivery_date)
            ->count() <= config('threshold');
    }

    public function scopeApprovedForClinic($query, string $clinic, $date = null): void
    {
        $query->whereNotNull('approved_at')
            ->where('clinic', $clinic)
            ->when($date, fn ($q) => $q->whereDate('preferred_delivery_date', $date));
    }

    public function scopeIsAnalysis(Builder $query): void
    {
        $query->with(['product' => function ($query) {
            $query->whereIn('sku', Product::KITS);
        }]);
    }

    public function scopeNumber($query, string $number)
    {
        $query->where('po_number', $number);
    }

    public function scopeReadyForShipping(Builder $query): void
    {
        $query->with('order')
            ->where(function (Builder $query) {
                $query->whereDoesntHave('order.discountUses')
                    ->orWhere(function (Builder $query) {
                        $query->whereHas('order.discountUses', function (Builder $query) {
                            $query->whereHas('discount', function ($query) {
                                $query->where('discounts.code', '!=', 'MERYLTEST');
                            });
                        });
                    });
            })
            ->whereNotNull('approved_at')
            ->where('shipping_status', self::SHIPPING_STATUS_READY)
            ->whereDate('preferred_delivery_date', Carbon::today()->addWeekdays(2));
    }

    public function cancel(): self
    {
        $this->approved_at = null;
        $this->shipping_status = null;
        $this->preferred_delivery_date = null;
        $this->preferred_delivery_date_confirmed_at = null;

        $this->save();

        return $this;
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function resultDocuments()
    {
        return $this->hasMany(ResultDocument::class);
    }

    public function getWithDnaFragmentationAttribute()
    {
        return $this->hasDnaFragmentation();
    }

    public function isShippable(): bool
    {
        return $this->isKit();
    }

    public function setClient(Client $client): self
    {
        $this->client_id = $client->id;
        $this->save();

        return $this;
    }

    public function downloadableLink()
    {
        $latestDocument = $this->latestResultDocument();

        return ! is_null($latestDocument) ? $latestDocument->downloadableLink : null;
    }

    public function latestResultDocument()
    {
        return $this->resultDocuments()->latest()->first();
    }

    public function currentHasResults(): bool
    {
        return $this->resultDocuments()->count() > 0;
    }

    public function initNumber(): self
    {
        $this->po_number = static::nextPoNumber();
        $this->save();

        return $this;
    }

    public function hasPackage(): bool
    {
        return ! is_null($this->package_id);
    }

    public function getUnitPrice()
    {
        return $this->price + $this->addons->reduce(fn ($price, Addon $addon) => $price + $addon->price);
    }

    public function getTotalPrice()
    {
        return $this->quantity * $this->getUnitPrice();
    }
}
