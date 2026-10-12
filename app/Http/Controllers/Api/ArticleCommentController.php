<?php

namespace App\Http\Controllers\Api;

use App\Enums\ArticleStatus;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleComment;
use App\Support\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reader comments. Listing is public; posting needs a signed-in account; a comment can be
 * deleted by its author or an admin. Comments exist only on PUBLISHED articles.
 */
class ArticleCommentController extends Controller
{
    private function publishedArticle(string $slug): Article
    {
        return Article::query()->where('slug', $slug)->where('status', ArticleStatus::PUBLISHED->value)->firstOrFail();
    }

    private function present(ArticleComment $c): array
    {
        $name = trim(($c->user->first_name ?? '').' '.($c->user->last_name ?? ''));

        return [
            'id' => $c->id,
            'body' => $c->body,
            'user_id' => $c->user_id,
            'author_name' => $name !== '' ? $name : 'Reader',
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    /** GET /articles/{slug}/comments/ */
    public function index(Request $request, string $slug): JsonResponse
    {
        $article = $this->publishedArticle($slug);
        $q = ArticleComment::query()
            ->where('article_id', $article->id)
            ->with('user:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->json(Page::make($q, $request, fn (ArticleComment $c) => $this->present($c)));
    }

    /** POST /articles/{slug}/comments/ {body} */
    public function store(Request $request, string $slug): JsonResponse
    {
        $article = $this->publishedArticle($slug);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:1000']]);

        $comment = ArticleComment::query()->create([
            'article_id' => $article->id,
            'user_id' => $request->user()->id,
            'body' => trim($data['body']),
        ]);

        return response()->json($this->present($comment->load('user:id,first_name,last_name')), 201);
    }

    /** DELETE /comments/{id}/ - own comment, or any comment for an admin. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $comment = ArticleComment::query()->findOrFail($id);
        $user = $request->user();
        if ($comment->user_id !== $user->id && ! $user->isAdmin()) {
            abort(403, 'You do not have permission to delete this comment.');
        }
        $comment->delete();

        return response()->json(null, 204);
    }
}
