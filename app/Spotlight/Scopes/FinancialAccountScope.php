<?php

namespace App\Spotlight\Scopes;

use WireElements\Pro\Components\Spotlight\SpotlightScope;

class FinancialAccountScope
{
    public static function make(): SpotlightScope
    {
        return SpotlightScope::forRoute('money.show', function ($scope, $request) {
            $account = $request->route('account');
            if ($account) {
                $scope->applyToken('account', [
                    'account' => [
                        'id' => $account->id,
                        'name' => $account->title,
                    ],
                ]);
            }
        });
    }
}
