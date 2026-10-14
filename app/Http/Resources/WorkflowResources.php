<?php

namespace App\Http\Resources;

use App\Models\ArticleReview;
use App\Models\PublishingSchedule;

/** JSON shapes identical to the Django reporters serializers. */
class WorkflowResources
{
    public static function review(ArticleReview $r): array
    {
        return [
            'id' => $r->id,
            'action' => $r->action->value,
            'from_status' => $r->from_status,
            'to_status' => $r->to_status,
            'reason' => $r->reason,
            'reviewer_email' => $r->reviewer?->email,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /** Needs article + scheduledBy loaded. */
    public static function schedule(PublishingSchedule $s): array
    {
        return [
            'id' => $s->id,
            'article_title' => $s->article?->title,
            'article_slug' => $s->article?->slug,
            'article_status' => $s->article?->status->value,
            'scheduled_for' => $s->scheduled_for?->toIso8601String(),
            'scheduled_by_email' => $s->scheduledBy?->email,
            'status' => $s->status,
            'executed_at' => $s->executed_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
