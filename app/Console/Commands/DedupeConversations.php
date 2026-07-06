<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

class DedupeConversations extends Command
{
    protected $signature = 'conversations:dedupe {--dry-run : Show what would happen without changing anything}';

    protected $description = 'Merge duplicate 1-on-1 conversations between the same two people, keeping all messages';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $conversations = Conversation::where('is_group', false)
            ->with('members:id')
            ->get();

        // Group conversations by their sorted pair of member IDs
        $groups = $conversations->groupBy(function ($conversation) {
            $ids = $conversation->members->pluck('id')->sort()->values()->all();
            return implode('-', $ids);
        });

        $mergedCount = 0;

        foreach ($groups as $pairKey => $group) {
            if ($group->count() <= 1) {
                continue; // no duplicates for this pair
            }

            // Keep the oldest conversation, merge the rest into it
            $sorted = $group->sortBy('id');
            $canonical = $sorted->first();
            $duplicates = $sorted->slice(1);

            $this->info("Pair [{$pairKey}]: keeping conversation #{$canonical->id}, merging " . $duplicates->count() . ' duplicate(s)');

            foreach ($duplicates as $dup) {
                $messageCount = $dup->messages()->count();
                $this->line("  - moving {$messageCount} message(s) from conversation #{$dup->id} into #{$canonical->id}");

                if (! $dryRun) {
                    $dup->messages()->update(['conversation_id' => $canonical->id]);
                    $dup->delete(); // conversation_user pivot rows cascade-delete automatically
                }

                $mergedCount++;
            }
        }

        if ($dryRun) {
            $this->warn("Dry run only — no changes made. Found {$mergedCount} duplicate conversation(s) to merge.");
            $this->line('Run without --dry-run to actually merge them.');
        } else {
            $this->info("Done. Merged {$mergedCount} duplicate conversation(s).");
        }

        return self::SUCCESS;
    }
}
