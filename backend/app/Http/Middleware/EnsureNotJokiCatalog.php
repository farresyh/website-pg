<?php

namespace App\Http\Middleware;

use App\Models\Game;
use App\Models\Package;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-126 F1: a joki game's packages exist only so an order has a package;
 * their price fields are never read, and joki keeps them itself. The one
 * gate on the Games & Packages write routes (create, edit, markup, cost,
 * status, delete), so no package screen can change or remove one.
 */
class EnsureNotJokiCatalog
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $package = $request->route('package');
        $game = $request->route('game');

        $isJoki = ($package instanceof Package && (bool) $package->game?->is_joki)
            || ($game instanceof Game && $game->is_joki);

        if ($isJoki) {
            throw ValidationException::withMessages(['package' => ['Joki packages are managed in Joki settings.']]);
        }

        return $next($request);
    }
}
