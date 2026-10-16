<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Portal\Http\Resources\PortalSubjectResource;
use App\Modules\Portal\Services\PortalSubjectResolver;
use App\Support\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Sujetos del portal (SDD §3.3, §4.3.10; RF-061): la ficha propia y los representados vigentes.
 * Es la ruta habilitante de la SPA del portal; el selector de RF-177 llega en MS-13.
 */
class PortalSubjectController extends Controller
{
    public function index(Request $request, PortalSubjectResolver $resolver): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return PortalSubjectResource::collection($resolver->for($user));
    }
}
