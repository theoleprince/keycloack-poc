<?php

namespace App\Http\Controllers;

use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contrôleur métier : ne connaît ni Sanctum ni Keycloak.
 * Le cloisonnement est assuré par le global scope BelongsToCompany.
 */
class NoteController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Note::query()->latest('id')->get()]);
    }

    public function show(Note $note): JsonResponse
    {
        return response()->json(['data' => $note]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        $note = new Note($data);
        $note->user_id = $request->user()->id;
        $note->save();

        return response()->json(['data' => $note->fresh()], 201);
    }
}
