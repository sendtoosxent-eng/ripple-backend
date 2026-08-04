<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class SafeBroadcast
{
    /**
     * Fire a broadcast event, but never let a broadcasting failure (bad Reverb config,
     * Reverb server down, network hiccup, etc.) break the actual user action that
     * triggered it. Posting a message/post/status should always succeed even if the
     * "live update" part fails — real-time is a bonus, not a dependency.
     */
    public static function send($event, bool $toOthersOnly = true): void
    {
        try {
            $broadcast = broadcast($event);
            if ($toOthersOnly) {
                $broadcast->toOthers();
            }
            // PendingBroadcast dispatches from its destructor. Destroy it inside this
            // try block so connection failures are caught instead of escaping later.
            unset($broadcast);
        } catch (Throwable $e) {
            Log::warning('Broadcast failed (non-fatal): ' . $e->getMessage(), [
                'event' => get_class($event),
            ]);
        }
    }
}
