<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ModelsRouteTest extends TestCase
{
    public function test_serves_a_model_file_from_public_models(): void
    {
        $dir = public_path('models');
        File::ensureDirectoryExists($dir);
        $file = $dir.'/__route_test__.bin';
        File::put($file, 'test-model-content');

        try {
            $response = $this->get('/models/__route_test__.bin');

            $response->assertOk();
            $response->assertHeader('Content-Type');
        } finally {
            File::delete($file);
        }
    }

    public function test_rejects_path_traversal_outside_models(): void
    {
        $this->get('/models/../../.env')->assertNotFound();
        $this->get('/models/%2e%2e/.env')->assertNotFound();
    }

    public function test_returns_404_for_missing_file(): void
    {
        $this->get('/models/does-not-exist.bin')->assertNotFound();
    }

    public function test_returns_404_when_models_dir_absent(): void
    {
        $dir = public_path('models');
        $dirExisted = is_dir($dir);

        if ($dirExisted) {
            File::moveDirectory($dir, $dir.'__tmp');
        }

        try {
            $this->get('/models/anything.bin')->assertNotFound();
        } finally {
            if ($dirExisted) {
                File::moveDirectory($dir.'__tmp', $dir);
            }
        }
    }
}