<?php

namespace App\Support;

use App\Models\Event;

/**
 * Classifies a money event by where the money actually went — the single
 * source of truth behind the briefing's spend/transfer split (MR-4) and the
 * per-event `direction` field (MR-13). Without this, a client has to guess
 * from the action name's `_to`/`_from` suffix, which breaks the moment a
 * new integration adds an action the client hasn't seen.
 */
class MoneyDirection
{
    /** Money left the account, going to a third party. */
    public const OUT = 'out';

    /** Money arrived in the account, from a third party. */
    public const IN = 'in';

    /** Money moved between the user's own accounts/pots — not spend, not income. */
    public const INTERNAL = 'internal';

    /** No money actually moved (a declined payment, a balance snapshot). */
    public const EXCLUDED = 'excluded';

    /** An action this classifier has no rule for yet. */
    public const UNKNOWN = 'unknown';

    /**
     * Actions that are always a transfer between the user's own accounts,
     * regardless of what the target object looks like.
     */
    private const INTERNAL_ACTIONS = [
        'pot_transfer_to',
        'pot_withdrawal_to',
    ];

    /** Outflow to a third party. */
    private const OUT_ACTIONS = [
        'payment_to',
        'card_payment_to',
        'bank_transfer_to',
        'direct_debit_to',
        'monzo_me_to',
        'monzo_flex_payment',
        'fee_paid_for',
        'other_debit_to',
        'interest_repaid',
    ];

    /** Inflow from a third party. */
    private const IN_ACTIONS = [
        'payment_from',
        'bank_transfer_from',
        'card_refund_from',
        'salary_received_from',
        'direct_credit_from',
        'monzo_me_from',
        'interest_earned',
        'fee_refunded_for',
        'other_credit_from',
        'monzo_flex_loan',
    ];

    /** No money actually moved. */
    private const EXCLUDED_ACTIONS = [
        'declined_payment_to',
        'had_balance',
    ];

    /**
     * Resolve the direction of a money event. `$event->actor` and
     * `$event->target` should be eager-loaded — this falls back to
     * action-name classification alone when they aren't, which is enough
     * for every action except a same-user transfer routed through an
     * otherwise "external" action (e.g. `bank_transfer_to` a tracked
     * external savings account).
     */
    public static function for(Event $event): string
    {
        $action = $event->action;

        if (in_array($action, self::EXCLUDED_ACTIONS, true)) {
            return self::EXCLUDED;
        }

        if (in_array($action, self::INTERNAL_ACTIONS, true) || self::targetIsOwnAccount($event)) {
            return self::INTERNAL;
        }

        if (in_array($action, self::OUT_ACTIONS, true)) {
            return self::OUT;
        }

        if (in_array($action, self::IN_ACTIONS, true)) {
            return self::IN;
        }

        return self::UNKNOWN;
    }

    /**
     * True when both sides of the event are accounts owned by the same user —
     * a transfer between the user's own accounts even when the action name
     * alone (e.g. `bank_transfer_to`) can't say so.
     */
    private static function targetIsOwnAccount(Event $event): bool
    {
        $actorUserId = $event->actor?->user_id;
        $target = $event->target;

        return $actorUserId !== null
            && $target !== null
            && $target->concept === 'account'
            && $target->user_id === $actorUserId;
    }
}
