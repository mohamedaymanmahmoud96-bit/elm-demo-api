<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->conversations()->withCount('messages')->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $conversation = Conversation::create(['title' => $data['title'] ?? null]);
        $conversation->users()->syncWithoutDetaching([...$data['user_ids'], $request->user()->id]);

        return response()->json($conversation->load('users:id,name,role'), 201);
    }

    public function messages(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->users()->whereKey($request->user()->id)->exists(), 403);

        return $conversation->messages()->with('sender:id,name,role')->latest()->paginate(30);
    }

    public function send(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->users()->whereKey($request->user()->id)->exists(), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return response()->json($conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $data['body'],
        ]), 201);
    }
}