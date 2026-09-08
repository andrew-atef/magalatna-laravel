<?php

declare(strict_types=1);

namespace App\Enums;

enum FlyerStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Expired = 'expired';
}
