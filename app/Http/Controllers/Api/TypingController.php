<?php
namespace App\Http\Controllers\Api;

use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\SafeBroadcast;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TypingController extends Controller
{
    private function members(Request $request, Conversation $conversation)
    {
        $members = $conversation->members()->get();
        abort_unless($members->contains('id', $request->user()->id), 403);
        if (! $conversation->is_group) {
            $peer = $members->firstWhere('id', '!=', $request->user()->id);
            abort_if($peer && ($request->user()->hasBlocked($peer->id) || $request->user()->isBlockedBy($peer->id)), 403);
        }
        return $members;
    }

    public function index(Request $request, Conversation $conversation)
    {
        $members = $this->members($request, $conversation);
        return response()->json(['typing' => $members->filter(fn ($member) => $member->id !== $request->user()->id
            && Cache::get('typing:'.$conversation->id.':'.$member->id, false))
            ->map(fn ($member) => ['user_id' => $member->id, 'name' => $member->name])->values()]);
    }

    public function update(Request $request, Conversation $conversation)
    {
        $this->members($request, $conversation);
        $data = $request->validate(['is_typing' => 'required|boolean']);
        $user = $request->user();
        $key = 'typing:'.$conversation->id.':'.$user->id;
        if ($data['is_typing']) Cache::put($key, true, 6);
        else Cache::forget($key);
        SafeBroadcast::send(new UserTyping($conversation->id, $user->id, $user->name, (bool) $data['is_typing']));
        return response()->noContent();
    }
}
