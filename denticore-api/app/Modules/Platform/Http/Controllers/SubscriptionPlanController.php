<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Http\Resources\SubscriptionPlanResource;
use App\Modules\Platform\Models\SubscriptionPlan;
use App\Support\Http\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Planes de suscripción (SDD §4.3.1; CUS-03, RF-022, DD-16).
 */
class SubscriptionPlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SubscriptionPlanResource::collection(SubscriptionPlan::query()->orderBy('id')->get());
    }
}
