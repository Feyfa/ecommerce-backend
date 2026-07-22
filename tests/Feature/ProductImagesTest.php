<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        Storage::fake('public');
        $this->seller = User::factory()->create();
        $this->actingAs($this->seller);
    }

    /** @test */
    public function seller_can_create_a_product_with_one_image(): void
    {
        $response = $this->post('/api/product', $this->createPayload(1));

        $response->assertOk()
            ->assertJsonPath('product.images.0.position', 1);

        $product = Product::with('images')->firstOrFail();

        $this->assertCount(1, $product->images);
        $this->assertSame($product->images->first()->path, $product->img);
        Storage::disk('public')->assertExists($product->img);
    }

    /** @test */
    public function seller_can_create_a_product_with_five_images(): void
    {
        $response = $this->post('/api/product', $this->createPayload(5));

        $response->assertOk();

        $product = Product::with('images')->firstOrFail();
        $this->assertSame([1, 2, 3, 4, 5], $product->images->pluck('position')->all());
        $this->assertSame($product->images->first()->path, $product->img);
    }

    /** @test */
    public function create_rejects_an_empty_or_oversized_image_collection(): void
    {
        $this->post('/api/product', [
            'user_id_seller' => $this->seller->id,
            'name' => 'Produk Test',
            'price' => 10000,
            'stock' => 2,
            'image_order' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images', 'image_order'], 'message');

        $this->post('/api/product', $this->createPayload(6))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images', 'image_order'], 'message');
    }

    /** @test */
    public function create_rejects_non_image_and_files_larger_than_one_megabyte(): void
    {
        $payload = [
            'user_id_seller' => $this->seller->id,
            'name' => 'Produk Test',
            'price' => 10000,
            'stock' => 2,
            'image_order' => ['new:0'],
        ];

        $this->post('/api/product', $payload + [
            'images' => [UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images.0'], 'message');

        $this->post('/api/product', $payload + [
            'images' => [UploadedFile::fake()->image('large.jpg')->size(1025)],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images.0'], 'message');
    }

    /** @test */
    public function create_rejects_malformed_image_manifests_without_server_errors(): void
    {
        $basePayload = [
            'user_id_seller' => $this->seller->id,
            'name' => 'Produk Test',
            'price' => 10000,
            'stock' => 2,
        ];

        $this->post('/api/product', $basePayload + [
            'images' => UploadedFile::fake()->image('product.jpg'),
            'image_order' => 'new:0',
        ])->assertUnprocessable()->assertJsonValidationErrors(['images', 'image_order'], 'message');

        $this->post('/api/product', $basePayload + [
            'images' => [UploadedFile::fake()->image('product.jpg')],
            'image_order' => [['new:0']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images', 'image_order.0'], 'message');
    }

    /** @test */
    public function product_list_rejects_a_malformed_product_cursor(): void
    {
        $this->get("/api/product/{$this->seller->id}?products_current_id=invalid")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['products_current_id'], 'message');

        $this->get("/api/product/{$this->seller->id}?products_current_id=".urlencode('"not-an-array"'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['products_current_id'], 'message');
    }

    /** @test */
    public function migration_backfills_a_legacy_product_image_as_position_one(): void
    {
        $product = Product::create([
            'user_id_seller' => $this->seller->id,
            'img' => 'product-imgs/legacy.jpg',
            'name' => 'Produk Legacy',
            'price' => 10000,
            'stock' => 1,
        ]);

        Schema::drop('product_images');
        $migration = require database_path('migrations/2026_07_21_000001_create_product_images_table.php');
        $migration->up();

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'path' => 'product-imgs/legacy.jpg',
            'position' => 1,
        ]);
    }

    /** @test */
    public function seller_can_reorder_keep_add_and_remove_product_images(): void
    {
        $product = Product::create([
            'user_id_seller' => $this->seller->id,
            'img' => 'product-imgs/first.jpg',
            'name' => 'Produk Lama',
            'price' => 12000,
            'stock' => 3,
        ]);
        $first = $product->images()->create(['path' => 'product-imgs/first.jpg', 'position' => 1]);
        $second = $product->images()->create(['path' => 'product-imgs/second.jpg', 'position' => 2]);
        Storage::disk('public')->put($first->path, 'first');
        Storage::disk('public')->put($second->path, 'second');

        $response = $this->post("/api/product/{$product->id}", [
            '_method' => 'PUT',
            'name' => 'Produk Baru',
            'price' => 15000,
            'stock' => 0,
            'images' => [UploadedFile::fake()->image('replacement.jpg')],
            'image_order' => ['new:0', $first->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('product.images.0.position', 1)
            ->assertJsonPath('product.images.1.id', $first->id);

        $product->refresh()->load('images');
        $this->assertSame($product->images[0]->path, $product->img);
        $this->assertSame($first->id, $product->images[1]->id);
        $this->assertDatabaseMissing('product_images', ['id' => $second->id]);
        Storage::disk('public')->assertMissing($second->path);
        Storage::disk('public')->assertExists($first->path);
    }

    /** @test */
    public function update_rejects_zero_images_and_images_from_another_product(): void
    {
        $product = $this->productWithImage('product-imgs/owner.jpg');
        $otherProduct = $this->productWithImage('product-imgs/other.jpg');

        $basePayload = [
            '_method' => 'PUT',
            'name' => 'Produk Test',
            'price' => 10000,
            'stock' => 1,
        ];

        $this->post("/api/product/{$product->id}", $basePayload + ['image_order' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images', 'image_order'], 'message');

        $this->post("/api/product/{$product->id}", $basePayload + [
            'image_order' => [$otherProduct->images->first()->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images'], 'message');
    }

    /** @test */
    public function deleting_a_product_removes_all_of_its_images(): void
    {
        $product = $this->productWithImage('product-imgs/delete-first.jpg');
        $product->images()->create(['path' => 'product-imgs/delete-second.jpg', 'position' => 2]);
        Storage::disk('public')->put('product-imgs/delete-first.jpg', 'first');
        Storage::disk('public')->put('product-imgs/delete-second.jpg', 'second');

        $this->delete("/api/product/{$product->user_id_seller}/{$product->id}")->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_images', ['product_id' => $product->id]);
        Storage::disk('public')->assertMissing('product-imgs/delete-first.jpg');
        Storage::disk('public')->assertMissing('product-imgs/delete-second.jpg');
    }

    /** @test */
    public function seller_cannot_create_or_update_products_for_another_seller(): void
    {
        $otherSeller = User::factory()->create();
        $otherProduct = Product::create([
            'user_id_seller' => $otherSeller->id,
            'img' => 'product-imgs/other-seller.jpg',
            'name' => 'Produk Seller Lain',
            'price' => 10000,
            'stock' => 2,
        ]);
        $otherImage = $otherProduct->images()->create([
            'path' => 'product-imgs/other-seller.jpg',
            'position' => 1,
        ]);

        $createPayload = $this->createPayload(1);
        $createPayload['user_id_seller'] = $otherSeller->id;
        $this->post('/api/product', $createPayload)->assertForbidden();

        $this->post("/api/product/{$otherProduct->id}", [
            '_method' => 'PUT',
            'name' => 'Tidak Boleh Berubah',
            'price' => 15000,
            'stock' => 1,
            'image_order' => [$otherImage->id],
        ])->assertNotFound();

        $this->assertDatabaseHas('products', [
            'id' => $otherProduct->id,
            'name' => 'Produk Seller Lain',
        ]);
    }

    private function createPayload(int $imageCount): array
    {
        $images = [];
        $imageOrder = [];

        for ($index = 0; $index < $imageCount; $index++) {
            $images[] = UploadedFile::fake()->image("product-{$index}.jpg");
            $imageOrder[] = "new:{$index}";
        }

        return [
            'user_id_seller' => $this->seller->id,
            'name' => 'Produk Test',
            'price' => 10000,
            'stock' => 2,
            'images' => $images,
            'image_order' => $imageOrder,
        ];
    }

    private function productWithImage(string $path): Product
    {
        $product = Product::create([
            'user_id_seller' => $this->seller->id,
            'img' => $path,
            'name' => 'Produk Test',
            'price' => 10000,
            'stock' => 2,
        ]);
        $product->images()->create(['path' => $path, 'position' => 1]);

        return $product->load('images');
    }
}
