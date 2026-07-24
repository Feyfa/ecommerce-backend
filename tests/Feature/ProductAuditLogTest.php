<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi audit produk tersimpan konsisten tanpa mengarsipkan file gambar.
 */
class ProductAuditLogTest extends TestCase
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
    public function successful_create_records_an_owner_scoped_product_snapshot(): void
    {
        $response = $this->post('/api/product', $this->createPayload());

        $response->assertOk();
        $product = Product::with('images')->sole();
        $audit = AuditLog::query()->sole();

        $this->assertSame(AuditEvent::PRODUCT_CREATED, $audit->event);
        $this->assertSame($this->seller->id, $audit->actor_user_id);
        $this->assertSame('product', $audit->subject_type);
        $this->assertSame($product->id, $audit->subject_id);
        $this->assertSame('Produk Audit', $audit->context['subject_name']);
        $this->assertSame([
            'price' => 12500,
            'stock' => 4,
            'image_count' => 1,
        ], $audit->context['product_snapshot']);

        $this->getJson('/api/audit-logs?event=product.created')
            ->assertOk()
            ->assertJsonPath('data.0.subject.name', 'Produk Audit')
            ->assertJsonPath('data.0.product_snapshot.image_count', 1)
            ->assertJsonMissingPath('data.0.context');
    }

    /** @test */
    public function update_records_only_real_value_changes_and_image_metadata(): void
    {
        $product = $this->productWithImages();
        $firstImage = $product->images[0];

        $this->post("/api/product/{$product->id}", [
            '_method' => 'PUT',
            'name' => 'Produk Audit Baru',
            'price' => 15000,
            'stock' => 4,
            'images' => [UploadedFile::fake()->image('replacement.jpg')],
            'image_order' => ['new:0', $firstImage->id],
        ])->assertOk();

        $audit = AuditLog::query()->sole();

        $this->assertSame(AuditEvent::PRODUCT_UPDATED, $audit->event);

        // PostgreSQL jsonb does not preserve object-key order. Rebuild each
        // payload in the contract order so this remains a strict value/type
        // assertion on both PostgreSQL CI and SQLite in-memory tests.
        $changes = array_map(static fn (array $change): array => [
            'field' => $change['field'],
            'label' => $change['label'],
            'before' => $change['before'],
            'after' => $change['after'],
        ], $audit->context['changes']);

        $this->assertSame([
            [
                'field' => 'name',
                'label' => 'Nama produk',
                'before' => 'Produk Audit',
                'after' => 'Produk Audit Baru',
            ],
            [
                'field' => 'price',
                'label' => 'Harga',
                'before' => 12500,
                'after' => 15000,
            ],
        ], $changes);

        $imageChanges = $audit->context['image_changes'];
        $this->assertSame([
            'before_count' => 2,
            'after_count' => 2,
            'added_count' => 1,
            'removed_count' => 1,
            'cover_changed' => true,
            'order_changed' => false,
        ], [
            'before_count' => $imageChanges['before_count'],
            'after_count' => $imageChanges['after_count'],
            'added_count' => $imageChanges['added_count'],
            'removed_count' => $imageChanges['removed_count'],
            'cover_changed' => $imageChanges['cover_changed'],
            'order_changed' => $imageChanges['order_changed'],
        ]);
        $this->assertStringNotContainsString('product-imgs/', json_encode($audit->context, JSON_THROW_ON_ERROR));
    }

    /** @test */
    public function identical_update_is_recorded_without_false_changes(): void
    {
        $product = $this->productWithImages(1);

        $this->post("/api/product/{$product->id}", [
            '_method' => 'PUT',
            'name' => 'Produk Audit',
            'price' => 12500,
            'stock' => 4,
            'image_order' => [$product->images[0]->id],
        ])->assertOk();

        $audit = AuditLog::query()->sole();

        $this->assertSame([], $audit->context['changes']);
        $this->assertSame(0, $audit->context['image_changes']['added_count']);
        $this->assertSame(0, $audit->context['image_changes']['removed_count']);
        $this->assertFalse($audit->context['image_changes']['cover_changed']);
        $this->assertFalse($audit->context['image_changes']['order_changed']);
    }

    /** @test */
    public function delete_keeps_the_last_snapshot_after_the_product_is_gone(): void
    {
        $product = $this->productWithImages(2);

        $this->delete("/api/product/{$this->seller->id}/{$product->id}")->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $audit = AuditLog::query()->sole();
        $this->assertSame(AuditEvent::PRODUCT_DELETED, $audit->event);

        $this->getJson("/api/audit-logs/{$audit->id}")
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('data.subject.id', $product->id)
                ->where('data.subject.name', 'Produk Audit')
                ->where('data.product_snapshot.price', 12500)
                ->where('data.product_snapshot.stock', 4)
                ->where('data.product_snapshot.image_count', 2)
                ->etc());
    }

    /** @test */
    public function changing_the_product_event_filter_after_pagination_starts_a_fresh_collection(): void
    {
        // --- step 1 - start - prepare more than one page of mixed product activity
        $occurredAt = CarbonImmutable::parse('2026-07-20 12:00:00');

        foreach (range(1, 22) as $index) {
            $this->createProductAudit(
                AuditEvent::PRODUCT_UPDATED,
                $occurredAt->subSeconds($index),
                "updated-{$index}"
            );
        }

        foreach (range(1, 3) as $index) {
            $this->createProductAudit(
                AuditEvent::PRODUCT_CREATED,
                $occurredAt->subMinutes($index),
                "created-{$index}"
            );
        }
        // --- step 1 - end - prepare more than one page of mixed product activity

        // --- step 2 - start - load and append the second unfiltered page
        $firstPage = $this->getJson('/api/audit-logs?per_page=20')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.has_more', true);
        $oldCursor = $firstPage->json('meta.next_cursor');

        $secondPage = $this->getJson('/api/audit-logs?per_page=20&cursor='.urlencode($oldCursor))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.has_more', false);
        // --- step 2 - end - load and append the second unfiltered page

        // --- step 3 - start - switch filter without reusing the previous collection cursor
        $filteredPage = $this->getJson('/api/audit-logs?per_page=20&event=product.created')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_cursor', null);

        $this->assertSame(
            array_fill(0, 3, AuditEvent::PRODUCT_CREATED->value),
            array_column($filteredPage->json('data'), 'event')
        );
        $this->assertEmpty(array_intersect(
            array_column($firstPage->json('data'), 'id'),
            array_column($filteredPage->json('data'), 'id')
        ));
        // The old cursor belongs to the previous collection and must not be carried into a filter change.
        $this->assertNotEmpty($oldCursor);
        $this->assertCount(25, array_unique(array_merge(
            array_column($firstPage->json('data'), 'id'),
            array_column($secondPage->json('data'), 'id')
        )));
        // --- step 3 - end - switch filter without reusing the previous collection cursor
    }

    /** @test */
    public function failed_validation_and_foreign_product_writes_do_not_create_audit_rows(): void
    {
        // --- step 1 - start - reject invalid create data
        $this->post('/api/product', [
            'user_id_seller' => $this->seller->id,
            'name' => 'No',
        ])->assertUnprocessable();
        // --- step 1 - end - reject invalid create data

        // --- step 2 - start - prepare a product owned by another seller
        $otherSeller = User::factory()->create();
        $otherProduct = Product::create([
            'user_id_seller' => $otherSeller->id,
            'img' => 'product-imgs/other-seller.jpg',
            'name' => 'Produk Seller Lain',
            'price' => 20000,
            'stock' => 8,
        ]);
        $otherImage = $otherProduct->images()->create([
            'path' => 'product-imgs/other-seller.jpg',
            'position' => 1,
        ]);
        Storage::disk('public')->put($otherImage->path, 'other-seller-image');
        // --- step 2 - end - prepare a product owned by another seller

        // --- step 3 - start - reject create, update, and delete attempts from this seller
        $payload = $this->createPayload();
        $payload['user_id_seller'] = $otherSeller->id;
        $this->post('/api/product', $payload)->assertForbidden();

        $this->post("/api/product/{$otherProduct->id}", [
            '_method' => 'PUT',
            'name' => 'Tidak Boleh Berubah',
            'price' => 25000,
            'stock' => 1,
            'image_order' => [$otherImage->id],
        ])->assertNotFound();

        $this->delete("/api/product/{$otherSeller->id}/{$otherProduct->id}")
            ->assertForbidden();
        // --- step 3 - end - reject create, update, and delete attempts from this seller

        // --- step 4 - start - verify ownership data and audit history remain unchanged
        $this->assertDatabaseHas('products', [
            'id' => $otherProduct->id,
            'user_id_seller' => $otherSeller->id,
            'name' => 'Produk Seller Lain',
            'price' => 20000,
            'stock' => 8,
        ]);
        $this->assertDatabaseHas('product_images', [
            'id' => $otherImage->id,
            'product_id' => $otherProduct->id,
            'path' => 'product-imgs/other-seller.jpg',
        ]);
        Storage::disk('public')->assertExists('product-imgs/other-seller.jpg');
        $this->assertDatabaseCount('audit_logs', 0);
        // --- step 4 - end - verify ownership data and audit history remain unchanged
    }

    /** @test */
    public function failed_update_and_missing_product_do_not_mutate_data_or_create_audit_rows(): void
    {
        // --- step 1 - start - reject invalid update data without changing the product
        $product = $this->productWithImages(1);

        $this->post("/api/product/{$product->id}", [
            '_method' => 'PUT',
            'name' => 'Tidak Boleh Tersimpan',
            'price' => 15000,
            'stock' => 0,
            'image_order' => [],
        ])->assertUnprocessable();

        $product->refresh()->load('images');
        $this->assertSame('Produk Audit', $product->name);
        $this->assertSame(12500, (int) $product->price);
        $this->assertSame(4, $product->stock);
        $this->assertCount(1, $product->images);
        // --- step 1 - end - reject invalid update data without changing the product

        // --- step 2 - start - reject a valid but nonexistent product UUID
        $missingProductId = (string) Str::uuid();

        $this->getJson("/api/product/{$this->seller->id}/{$missingProductId}")
            ->assertNotFound()
            ->assertJsonPath('status', 404)
            ->assertJsonPath('message', 'Product Not Found')
            ->assertJsonMissingPath('product');
        // --- step 2 - end - reject a valid but nonexistent product UUID

        // Failed requests do not represent successful domain operations and must not be audited.
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /** @test */
    public function audit_failure_rolls_back_product_database_and_uploaded_files(): void
    {
        $this->mock(AuditLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordProductCreated')
                ->once()
                ->andThrow(new RuntimeException('Audit persistence failed.'));
        });

        $this->post('/api/product', $this->createPayload())->assertServerError();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('product-imgs'));
    }

    /** @test */
    public function audit_failure_rolls_back_update_and_preserves_the_previous_images(): void
    {
        $product = $this->productWithImages(2);
        $beforeImageIds = $product->images->pluck('id')->all();

        $this->mock(AuditLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordProductUpdated')
                ->once()
                ->andThrow(new RuntimeException('Audit persistence failed.'));
        });

        $this->post("/api/product/{$product->id}", [
            '_method' => 'PUT',
            'name' => 'Perubahan Harus Rollback',
            'price' => 15000,
            'stock' => 0,
            'images' => [UploadedFile::fake()->image('new.jpg')],
            'image_order' => ['new:0', $product->images[0]->id],
        ])->assertServerError();

        $product->refresh()->load('images');
        $this->assertSame('Produk Audit', $product->name);
        $this->assertSame(12500, (int) $product->price);
        $this->assertSame($beforeImageIds, $product->images->pluck('id')->all());
        $this->assertCount(2, Storage::disk('public')->allFiles('product-imgs'));
    }

    /** @test */
    public function audit_failure_rolls_back_delete_and_keeps_product_files(): void
    {
        $product = $this->productWithImages(2);

        $this->mock(AuditLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('productSnapshot')->once()->andReturn([
                'price' => 12500,
                'stock' => 4,
                'image_count' => 2,
            ]);
            $mock->shouldReceive('recordProductDeleted')
                ->once()
                ->andThrow(new RuntimeException('Audit persistence failed.'));
        });

        $this->delete("/api/product/{$this->seller->id}/{$product->id}")->assertServerError();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseCount('product_images', 2);
        Storage::disk('public')->assertExists('product-imgs/product-0.jpg');
        Storage::disk('public')->assertExists('product-imgs/product-1.jpg');
    }

    private function createPayload(): array
    {
        return [
            'user_id_seller' => $this->seller->id,
            'name' => 'Produk Audit',
            'price' => 12500,
            'stock' => 4,
            'images' => [UploadedFile::fake()->image('product.jpg')],
            'image_order' => ['new:0'],
        ];
    }

    private function productWithImages(int $imageCount = 2): Product
    {
        $product = Product::create([
            'user_id_seller' => $this->seller->id,
            'img' => 'product-imgs/product-0.jpg',
            'name' => 'Produk Audit',
            'price' => 12500,
            'stock' => 4,
        ]);

        for ($index = 0; $index < $imageCount; $index++) {
            $path = "product-imgs/product-{$index}.jpg";
            $product->images()->create(['path' => $path, 'position' => $index + 1]);
            Storage::disk('public')->put($path, "image-{$index}");
        }

        return $product->load('images');
    }

    /**
     * Membuat row audit produk deterministik untuk skenario pagination tanpa
     * menjalankan mutation produk atau menyentuh storage nyata.
     */
    private function createProductAudit(
        AuditEvent $event,
        CarbonImmutable $occurredAt,
        string $sequence
    ): AuditLog {
        return AuditLog::create([
            'actor_user_id' => $this->seller->id,
            'actor_clerk_user_id' => $this->seller->clerk_user_id,
            'event' => $event,
            'category' => $event->category(),
            'subject_type' => 'product',
            'subject_id' => (string) Str::uuid(),
            'context' => [
                'subject_name' => "Produk {$sequence}",
                'product_snapshot' => [
                    'price' => 12500,
                    'stock' => 4,
                    'image_count' => 1,
                ],
            ],
            'ip_address' => '127.0.0.1',
            'idempotency_key' => hash('sha256', "product-pagination|{$sequence}"),
            'occurred_at' => $occurredAt,
        ]);
    }
}
