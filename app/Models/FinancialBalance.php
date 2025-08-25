<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FinancialBalance extends Model
{
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $table = 'financial_balances';
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'financial_account_id',
        'balance',
        'date',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'id' => 'string',
        'user_id' => 'string',
        'financial_account_id' => 'string',
        'balance' => 'decimal:2',
        'date' => 'date',
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

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function getFormattedBalanceAttribute(): string
    {
        $account = $this->financialAccount;
        if (!$account) {
            return number_format($this->balance, 2);
        }

        return $account->currency_symbol . number_format($this->balance, 2);
    }

    public function getFormattedDateAttribute(): string
    {
        return $this->date->format('jS F Y');
    }
}