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
}
