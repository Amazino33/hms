<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddPerformanceHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Add caching headers for static assets
        if ($this->isStaticAsset($request)) {
            $response->header('Cache-Control', 'public, max-age=31536000, immutable');
        }

        // Add compression hint
        $response->header('Vary', 'Accept-Encoding');

        // NOTE: removed hard-coded preload for `/build/assets/app.css` because
        // the build pipeline emits hashed filenames (Vite). Use `@vite()` in
        // Blade to inject correct asset links instead. Adding a stale/static
        // preload path caused the browser to request a missing file which
        // returned HTML and triggered MIME errors.

        // Add security and performance headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }

    /**
     * Check if the request is for a static asset
     */
    protected function isStaticAsset(Request $request): bool
    {
        return $request->is('build/*') || 
               $request->is('*.js') || 
               $request->is('*.css') || 
               $request->is('*.woff*') || 
               $request->is('*.ttf') || 
               $request->is('*.jpg') || 
               $request->is('*.jpeg') || 
               $request->is('*.png') || 
               $request->is('*.gif') || 
               $request->is('*.svg') || 
               $request->is('*.ico');
    }
}
