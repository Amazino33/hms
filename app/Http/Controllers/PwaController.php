<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PwaController extends Controller
{
    public function manifest(Request $request): Response
    {
        $manifest = [
            "name" => "HMS - Hotel Management System",
            "short_name" => "HMS",
            "start_url" => "/",
            "display" => "standalone",
            "background_color" => "#ffffff",
            "theme_color" => "#1f2937",
            "icons" => [
                [
                    "src" => "/apple-touch-icon.png",
                    "sizes" => "180x180",
                    "type" => "image/png"
                ]
            ]
        ];

        return response()->json($manifest, 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET',
            'Access-Control-Allow-Headers' => 'Content-Type'
        ]);
    }
}