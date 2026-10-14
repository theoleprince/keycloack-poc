<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;

class ProjectController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Project::query()->latest('id')->get()]);
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json(['data' => $project]);
    }
}
