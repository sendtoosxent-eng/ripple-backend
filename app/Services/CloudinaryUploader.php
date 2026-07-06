<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class CloudinaryUploader
{
    /**
     * Upload a file to Cloudinary and return its permanent, public URL.
     * Using Cloudinary instead of local disk storage because Render's free
     * tier wipes local files on every restart/redeploy.
     */
    public static function upload(UploadedFile $file, string $folder = 'ripple'): string
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $preset = config('services.cloudinary.upload_preset');

        if (! $cloudName || ! $preset) {
            throw new \RuntimeException('Cloudinary is not configured. Set CLOUDINARY_CLOUD_NAME and CLOUDINARY_UPLOAD_PRESET.');
        }

        $response = Http::attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post("https://api.cloudinary.com/v1_1/{$cloudName}/auto/upload", [
                'upload_preset' => $preset,
                'folder' => $folder,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Cloudinary upload failed: ' . $response->body());
        }

        return $response->json('secure_url');
    }

    /**
     * Insert a resize/quality transformation into a Cloudinary URL so thumbnails
     * (avatars, small previews) don't ship the full multi-MB original over mobile data.
     * Leaves non-Cloudinary URLs (or audio/voice files) untouched.
     */
    public static function resized(?string $url, int $width): ?string
    {
        if (! $url || ! str_contains($url, '/upload/')) {
            return $url;
        }

        return str_replace('/upload/', "/upload/w_{$width},c_fill,q_auto,f_auto/", $url);
    }
}
