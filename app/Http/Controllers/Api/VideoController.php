<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Promoted videos. Public: GET active/ (the newest enabled video - the home page shows one).
 * Admin (perm ads.manage): list / create / update / delete.
 */
class VideoController extends Controller
{
    /** GET /videos/active/ -> [] or [{...}] (newest enabled video only). */
    public function active(): JsonResponse
    {
        $video = Video::query()->where('is_active', true)->orderByDesc('created_at')->orderByDesc('id')->first();

        return response()->json($video ? [$video->toArray()] : []);
    }

    public function index(): JsonResponse
    {
        return response()->json(Video::query()->orderByDesc('created_at')->orderByDesc('id')->get()->map->toArray()->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'url' => ['required', 'string', 'url:http,https', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $video = Video::query()->create([
            'title' => trim($data['title'] ?? ''),
            'url' => trim($data['url']),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($video->toArray(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $video = Video::query()->findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'url' => ['sometimes', 'string', 'url:http,https', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('title', $data)) {
            $data['title'] = trim($data['title'] ?? '');
        }
        if (isset($data['url'])) {
            $data['url'] = trim($data['url']);
        }
        $video->fill($data)->save();

        return response()->json($video->toArray());
    }

    public function destroy(int $id): JsonResponse
    {
        Video::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
