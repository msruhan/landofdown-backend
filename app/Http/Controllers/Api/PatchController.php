<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patch;
use App\Services\MetaTrackerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatchController extends Controller
{
    public function __construct(private readonly MetaTrackerService $meta) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->meta->listPatches()]);
    }

    public function show(int $id): JsonResponse
    {
        $patch = Patch::findOrFail($id);

        return response()->json(['data' => $patch]);
    }

    public function meta(Request $request): JsonResponse
    {
        $patchId = $request->integer('patch_id') ?: null;

        return response()->json($this->meta->overview($patchId));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => 'required|string|max:32|unique:patches,version',
            'name' => 'nullable|string|max:255',
            'release_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $patch = Patch::create($data);

        return response()->json(['data' => $patch], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $patch = Patch::findOrFail($id);
        $data = $request->validate([
            'version' => 'sometimes|required|string|max:32|unique:patches,version,'.$id,
            'name' => 'nullable|string|max:255',
            'release_date' => 'sometimes|required|date',
            'notes' => 'nullable|string',
        ]);
        $patch->update($data);

        return response()->json(['data' => $patch]);
    }

    public function destroy(int $id): JsonResponse
    {
        $patch = Patch::findOrFail($id);
        $patch->delete();

        return response()->json(['message' => 'Patch deleted']);
    }
}
