<?php

namespace App\Services\QuickLog;

enum QuickLogIntent: string
{
    case Log = 'log';
    case FlintNote = 'flint_note';
    case Command = 'command';
    case Search = 'search';
    case Unsure = 'unsure';

    /** @return array<int, string> Intents a user can pick or commit (everything except unsure). */
    public static function choosable(): array
    {
        return [self::Log->value, self::FlintNote->value, self::Command->value, self::Search->value];
    }
}
