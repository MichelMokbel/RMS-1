<?php

namespace App\Services\PastryOrders;

use App\Models\PastryOrder;
use App\Models\PastryOrderImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PastryOrderImageService
{
    /**
     * Store a single image file to S3 and return the key.
     */
    public function store(UploadedFile $file, string $orderNumber): string
    {
        $year      = now()->format('Y');
        $month     = now()->format('m');
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg';
        $uuid      = Str::uuid()->toString();
        $key       = "pastry-orders/{$year}/{$month}/{$orderNumber}/{$uuid}.{$extension}";

        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            throw ValidationException::withMessages([
                'images' => __('Unable to read the uploaded image. Please try again.'),
            ]);
        }

        try {
            Storage::disk('s3')->put($key, $stream);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'images' => __('Image upload failed. Please check storage settings and try again.'),
            ]);
        } finally {
            fclose($stream);
        }

        return $key;
    }

    /**
     * Store multiple uploaded files and attach them to the given order.
     * Returns the created PastryOrderImage records.
     *
     * @param  UploadedFile[]  $files
     */
    public function storeMultiple(array $files, PastryOrder $order, int $startSortOrder = 0): void
    {
        foreach ($files as $idx => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $key = $this->store($file, $order->order_number);

            PastryOrderImage::create([
                'pastry_order_id' => $order->id,
                'image_path'      => $key,
                'image_disk'      => 's3',
                'sort_order'      => $startSortOrder + $idx,
                'created_at'      => now(),
            ]);
        }
    }

    /**
     * Delete a single image from storage.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('s3')->delete($path);
    }

    /**
     * Delete all images belonging to an order (storage + DB rows).
     */
    public function deleteAll(PastryOrder $order): void
    {
        foreach ($order->images as $image) {
            $this->delete($image->image_path);
        }

        $order->images()->delete();
    }

    /**
     * Generate a pre-signed URL (15-min TTL) for a single S3 key.
     */
    public function presignedUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $config = config('filesystems.disks.s3');
        $s3ClientClass = 'Aws\\S3\\S3Client';

        if (! class_exists($s3ClientClass)) {
            return null;
        }

        $s3 = new $s3ClientClass([
            'version'     => 'latest',
            'region'      => $config['region'] ?? 'us-east-1',
            'credentials' => [
                'key'    => $config['key'] ?? null,
                'secret' => $config['secret'] ?? null,
            ],
        ]);

        $command = $s3->getCommand('GetObject', [
            'Bucket' => $config['bucket'] ?? null,
            'Key'    => $path,
        ]);

        $request = $s3->createPresignedRequest($command, '+15 minutes');

        return (string) $request->getUri();
    }

    /**
     * Generate pre-signed URLs for all images of an order.
     * Returns an array of ['id', 'url', 'sort_order'].
     */
    public function presignedUrlsForOrder(PastryOrder $order): array
    {
        return $order->images->map(function (PastryOrderImage $img) {
            return [
                'id'         => $img->id,
                'url'        => $this->presignedUrl($img->image_path),
                'sort_order' => $img->sort_order,
            ];
        })->values()->all();
    }
}
