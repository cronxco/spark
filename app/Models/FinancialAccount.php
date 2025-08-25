<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FinancialAccount extends Model
{
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $table = 'financial_accounts';
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'integration_id',
        'name',
        'account_type',
        'provider',
        'account_number',
        'sort_code',
        'currency',
        'interest_rate',
        'start_date',
        'metadata',
    ];

    protected $casts = [
        'id' => 'string',
        'user_id' => 'string',
        'integration_id' => 'string',
        'interest_rate' => 'decimal:2',
        'start_date' => 'date',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }

    public function balances()
    {
        return $this->hasMany(FinancialBalance::class)->orderBy('date', 'desc');
    }

    public function latestBalance()
    {
        return $this->hasOne(FinancialBalance::class)->latestOfMany('date');
    }

    public function getAccountTypeLabelAttribute(): string
    {
        $types = [
            'current_account' => 'Current Account',
            'savings_account' => 'Savings Account',
            'mortgage' => 'Mortgage',
            'investment_account' => 'Investment Account',
            'credit_card' => 'Credit Card',
            'loan' => 'Loan',
            'pension' => 'Pension',
            'other' => 'Other',
        ];

        return $types[$this->account_type] ?? $this->account_type;
    }

    public function getCurrencySymbolAttribute(): string
    {
        $symbols = [
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
        ];

        return $symbols[$this->currency] ?? $this->currency;
    }

    public function getFormattedInterestRateAttribute(): ?string
    {
        if ($this->interest_rate === null) {
            return null;
        }

        return number_format($this->interest_rate, 2) . '%';
    }

    public function getCurrentBalanceAttribute(): ?float
    {
        return $this->latestBalance?->balance;
    }

    public function getFormattedCurrentBalanceAttribute(): ?string
    {
        if ($this->current_balance === null) {
            return null;
        }

        return $this->currency_symbol . number_format($this->current_balance, 2);
    }
}