<?php

namespace App\Spotlight\Queries\Navigation;

use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class SettingsNavigationQuery
{
    /**
     * Create Spotlight query for settings navigation.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            $results = collect();

            // Only show settings if query contains "settings" or specific setting names
            $showSettings = blank($query) ||
                str_contains(strtolower($query), 'settings') ||
                str_contains(strtolower($query), 'profile') ||
                str_contains(strtolower($query), 'password') ||
                str_contains(strtolower($query), 'sessions') ||
                str_contains(strtolower($query), 'notifications') ||
                str_contains(strtolower($query), 'integrations') ||
                str_contains(strtolower($query), 'api') ||
                str_contains(strtolower($query), 'tokens');

            if (! $showSettings) {
                return $results;
            }

            if (blank($query) || str_contains('profile', strtolower($query)) || str_contains('settings', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Profile Settings')
                        ->setSubtitle('Manage your profile information')
                        ->setTypeahead('Go to Profile Settings')
                        ->setIcon('user')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('settings.profile')])
                );
            }

            if (blank($query) || str_contains('password', strtolower($query)) || str_contains('settings', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Password Settings')
                        ->setSubtitle('Change your password')
                        ->setTypeahead('Go to Password Settings')
                        ->setIcon('lock-closed')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('settings.password')])
                );
            }

            if (blank($query) || str_contains('sessions', strtolower($query)) || str_contains('settings', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Sessions Settings')
                        ->setSubtitle('Manage your active sessions')
                        ->setTypeahead('Go to Sessions Settings')
                        ->setIcon('computer-desktop')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('settings.sessions')])
                );
            }

            if (blank($query) || str_contains('notifications', strtolower($query)) || str_contains('settings', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Notification Settings')
                        ->setSubtitle('Configure notification preferences')
                        ->setTypeahead('Go to Notification Settings')
                        ->setIcon('bell')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('settings.notifications')])
                );
            }

            if (blank($query) || str_contains('integrations', strtolower($query)) || str_contains('settings', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('Integrations')
                        ->setSubtitle('Manage your service integrations')
                        ->setTypeahead('Go to Integrations')
                        ->setIcon('puzzle-piece')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('integrations.index')])
                );
            }

            if (blank($query) || str_contains('api', strtolower($query)) || str_contains('tokens', strtolower($query)) || str_contains('settings', strtolower($query))) {
                $results->push(
                    SpotlightResult::make()
                        ->setTitle('API Tokens')
                        ->setSubtitle('Manage API access tokens')
                        ->setTypeahead('Go to API Tokens')
                        ->setIcon('key')
                        ->setGroup('navigation')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('settings.api-tokens')])
                );
            }

            return $results;
        });
    }
}
