<?php

namespace Tests\Feature\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Uploads a receipt as the user behind $headers (receipts attach only for their uploader). */
trait UploadsReceipts
{
    private bool $receiptsDiskFaked = false;

    /** A JPEG (by content, so mime sniffing sees image/jpeg) without needing the GD extension. */
    protected static function fakeJpeg(string $name = 'slip.jpg', int $padKb = 0): UploadedFile
    {
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        return UploadedFile::fake()->createWithContent($name, $jpeg . str_repeat("\0", $padKb * 1024));
    }

    protected function uploadReceipt(array $headers, ?UploadedFile $file = null): int
    {
        if (!$this->receiptsDiskFaked) {
            Storage::fake('receipts');
            $this->receiptsDiskFaked = true;
        }

        $r = $this->post('/api/v1/admin/payment-receipts', [
            'file' => $file ?? self::fakeJpeg(),
        ], $headers + ['Accept' => 'application/json']);
        $r->assertStatus(201);

        return (int) $r->json('data.id');
    }
}
