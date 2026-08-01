<?php

namespace Tests\Feature;

use App\Models\Alamat;
use App\Models\TransactionInvoice;
use App\Models\TransactionUser;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AddressLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private string $verificationCountryCode = 'id';

    private int $verificationStatus = 200;

    /**
     * Menyiapkan fixture dan dependency sebelum setiap pengujian.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        config(['services.geoapify.key' => 'geoapify-test-key']);
        $this->fakeIndonesiaVerification();
        $this->buyer = User::factory()->create();
        $this->actingAs($this->buyer);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario buyer can store a
     * pinpoint address.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_can_store_a_pinpoint_address(): void
    {
        $response = $this->postJson('/api/alamat/buyer', array_merge($this->buyerFields(), [
            'enable' => true,
        ], $this->mapFields()));

        $response->assertOk()
            ->assertJsonPath('alamats.0.location_source', 'map')
            ->assertJsonPath('alamats.0.alamat', 'Blok B No. 12, Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia');

        $this->assertDatabaseHas('alamats', [
            'user_id' => $this->buyer->id,
            'location_source' => 'map',
            'formatted_address' => 'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
            'address_detail' => 'Blok B No. 12',
        ]);
    }

    /**
     * Memverifikasi bahwa payload Pinpoint memerlukan koordinat dan detail alamat dari client.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function map_address_requires_coordinates_and_detail(): void
    {
        $this->postJson('/api/alamat/buyer', array_merge($this->buyerFields(), [
            'enable' => true,
            'location_source' => 'map',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude', 'address_detail'], 'message');
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario existing address
     * cannot be changed back to manual.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function existing_address_cannot_be_changed_back_to_manual(): void
    {
        $alamat = Alamat::create(array_merge([
            'user_id' => $this->buyer->id,
            'type' => 'buyer',
            'enable' => true,
        ], $this->buyerFields(), $this->mapFields()));

        $this->putJson("/api/alamat/buyer/{$alamat->id}", array_merge($this->buyerFields(), [
            'alamat' => 'Jalan Melati No. 8, Bandung',
            'location_source' => 'manual',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['location_source'], 'message');

        $alamat->refresh();
        $this->assertSame('map', $alamat->location_source);
        $this->assertSame(-6.1755043, $alamat->latitude);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario legacy payload
     * without location source is rejected for new addresses.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function legacy_payload_without_location_source_is_rejected_for_new_addresses(): void
    {
        $this->postJson('/api/alamat/buyer', array_merge($this->buyerFields(), [
            'alamat' => 'Alamat manual lama',
            'enable' => false,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['location_source'], 'message');

        $this->assertDatabaseMissing('alamats', ['user_id' => $this->buyer->id]);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario server rejects a
     * pinpoint verified outside indonesia.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function server_rejects_a_pinpoint_verified_outside_indonesia(): void
    {
        $this->verificationCountryCode = 'bd';

        $this->postJson('/api/alamat/buyer', array_merge($this->buyerFields(), [
            'enable' => true,
        ], $this->mapFields([
            'latitude' => 23.8103,
            'longitude' => 90.4125,
        ])))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude']);

        $this->assertDatabaseMissing('alamats', ['user_id' => $this->buyer->id]);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario server uses the
     * verified address instead of client metadata.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function server_uses_the_verified_address_instead_of_client_metadata(): void
    {
        $this->postJson('/api/alamat/buyer', array_merge($this->buyerFields(), [
            'enable' => true,
        ], $this->mapFields([
            'formatted_address' => 'Alamat palsu dari browser',
            'geoapify_place_id' => 'client-place-id',
        ])))->assertOk();

        $this->assertDatabaseHas('alamats', [
            'user_id' => $this->buyer->id,
            'formatted_address' => 'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
            'geoapify_place_id' => 'verified-place-id',
        ]);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario provider failure
     * rejects the write without changing existing data.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function provider_failure_rejects_the_write_without_changing_existing_data(): void
    {
        $alamat = Alamat::create(array_merge([
            'user_id' => $this->buyer->id,
            'type' => 'buyer',
            'enable' => true,
        ], $this->buyerFields(), $this->mapFields()));

        $this->verificationStatus = 429;

        $this->putJson("/api/alamat/buyer/{$alamat->id}", array_merge([
            'place' => 'Kantor',
            'name' => 'Nama Baru',
            'phone' => '089999999999',
        ], $this->mapFields([
            'address_detail' => 'Lantai 2',
        ])))
            ->assertStatus(503)
            ->assertJsonPath('code', 'LOCATION_VERIFICATION_UNAVAILABLE');

        $alamat->refresh();
        $this->assertSame('Rumah', $alamat->place);
        $this->assertSame('Penerima Test', $alamat->name);
        $this->assertSame('Blok B No. 12', $alamat->address_detail);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario checkout rejects
     * an active legacy manual buyer address.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function checkout_rejects_an_active_legacy_manual_buyer_address(): void
    {
        Alamat::create([
            'user_id' => $this->buyer->id,
            'type' => 'buyer',
            'alamat' => 'Alamat manual lama',
            'location_source' => 'manual',
            'enable' => true,
        ]);

        $this->getJson('/api/checkout/data')
            ->assertStatus(409)
            ->assertJsonPath('code', 'ADDRESS_REQUIRES_VERIFICATION');
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario buyer cannot
     * select a legacy manual address.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_cannot_select_a_legacy_manual_address(): void
    {
        $manualAddress = Alamat::create([
            'user_id' => $this->buyer->id,
            'type' => 'buyer',
            'alamat' => 'Alamat manual lama',
            'location_source' => 'manual',
            'enable' => false,
        ]);

        $this->putJson("/api/alamat-enable/buyer/{$manualAddress->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'ADDRESS_REQUIRES_VERIFICATION');

        $this->assertDatabaseHas('alamats', [
            'id' => $manualAddress->id,
            'enable' => false,
        ]);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario checkout rejects
     * a legacy manual seller address.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function checkout_rejects_a_legacy_manual_seller_address(): void
    {
        $seller = User::factory()->create();
        Alamat::create(array_merge([
            'user_id' => $seller->id,
            'type' => 'seller',
            'enable' => false,
        ], $this->mapFields()));
        Alamat::create([
            'user_id' => $seller->id,
            'type' => 'seller',
            'alamat' => 'Alamat toko manual lama',
            'location_source' => 'manual',
            'enable' => true,
        ]);

        $service = $this->app->make(CheckoutService::class);

        $this->assertTrue($service->hasUnverifiedSellerAddress([[
            'user_id_seller' => $seller->id,
        ]]));
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario buyer cannot
     * delete another users address.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_cannot_delete_another_users_address(): void
    {
        $otherAddress = Alamat::create([
            'user_id' => User::factory()->create()->id,
            'type' => 'buyer',
            'alamat' => 'Alamat user lain',
            'location_source' => 'manual',
            'enable' => true,
        ]);

        $this->deleteJson("/api/alamat/buyer/{$otherAddress->id}")
            ->assertStatus(400)
            ->assertJsonPath('message', 'Alamat Tidak Ditemukan');

        $this->assertDatabaseHas('alamats', ['id' => $otherAddress->id]);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario buyer cannot
     * update another users address.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function buyer_cannot_update_another_users_address(): void
    {
        $otherAddress = Alamat::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'type' => 'buyer',
            'enable' => true,
        ], $this->buyerFields(), $this->mapFields()));

        $this->putJson("/api/alamat/buyer/{$otherAddress->id}", array_merge([
            'place' => 'Alamat Disusupi',
            'name' => 'Bukan Pemilik',
            'phone' => '081111111111',
        ], $this->mapFields()))
            ->assertStatus(400)
            ->assertJsonPath('message', 'Alamat Tidak Ditemukan');

        $otherAddress->refresh();
        $this->assertSame('Rumah', $otherAddress->place);
        $this->assertSame('Penerima Test', $otherAddress->name);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario seller can store
     * a pinpoint with required detail.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function seller_can_store_a_pinpoint_with_required_detail(): void
    {
        $this->putJson('/api/company', array_merge([
            'name' => 'Toko Pinpoint',
            'email' => $this->buyer->email,
            'phone' => '081234567890',
            'description' => 'Toko pengujian',
        ], $this->mapFields()))
            ->assertOk()
            ->assertJsonPath('company.location_source', 'map');

        $this->assertDatabaseHas('alamats', [
            'user_id' => $this->buyer->id,
            'type' => 'seller',
            'location_source' => 'map',
        ]);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario checkout copies
     * buyer and seller location snapshots.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function checkout_copies_buyer_and_seller_location_snapshots(): void
    {
        $seller = User::factory()->create();
        $buyerAddress = Alamat::create(array_merge([
            'user_id' => $this->buyer->id,
            'type' => 'buyer',
            'enable' => true,
        ], $this->mapFields()));
        Alamat::create(array_merge([
            'user_id' => $seller->id,
            'type' => 'seller',
            'enable' => true,
        ], $this->mapFields([
            'latitude' => -6.9147440,
            'longitude' => 107.6098100,
            'address_detail' => 'Gudang utama',
        ])));

        $service = $this->app->make(CheckoutService::class);
        $result = $service->saveCheckoutToDatabase(
            user_id_buyer: $this->buyer->id,
            checkouts: [[
                'user_id_seller' => $seller->id,
                'keranjangs' => [[
                    'p_id' => (string) Str::uuid(),
                    'p_price' => 10000,
                    'k_total' => 1,
                    'k_total_price' => 10000,
                ]],
            ]],
            kurirs: [[
                'user_id_seller' => $seller->id,
                'name' => 'JNT',
                'price' => 15000,
                'estimation' => '1 hari',
            ]],
            noteds: [['user_id_seller' => $seller->id, 'noted' => '']],
            alamat_buyer: $buyerAddress,
            payment_method: 'va',
            payment_slug: 'bca',
            payment_name: 'BCA Virtual Account',
            expired_at: now()->addDay()->format('Y-m-d H:i:s'),
            price: 25000,
            checkout_key: hash('sha256', 'address-location-test'),
            dataXendit: ['account_number' => '123456', 'external_id' => 'test-va'],
        );

        $this->assertSame('success', $result['status']);
        $invoice = TransactionInvoice::firstOrFail();
        $transactionUser = TransactionUser::firstOrFail();
        $this->assertSame(-6.1755043, $invoice->alamat_buyer_latitude);
        $this->assertSame(106.8272634, $invoice->alamat_buyer_longitude);
        $this->assertSame('map', $invoice->alamat_buyer_location_source);
        $this->assertSame(-6.9147440, $transactionUser->alamat_seller_latitude);
        $this->assertSame(107.6098100, $transactionUser->alamat_seller_longitude);
        $this->assertSame('map', $transactionUser->alamat_seller_location_source);
    }

    /**
     * Memverifikasi aturan verifikasi pinpoint alamat buyer dan seller pada skenario checkout snapshot
     * detects an address change.
     *
     * Test memalsukan response verifikasi lokasi, menjalankan mutasi alamat atau checkout, lalu
     * memastikan ownership, metadata server, dan snapshot lokasi dipertahankan dengan benar.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function checkout_snapshot_detects_an_address_change(): void
    {
        $service = $this->app->make(CheckoutService::class);
        $backendSnapshot = ['clientComparable' => [
            'alamat_id' => 'address-new',
            'alamat_updated_at' => '2026-07-26T10:00:00.000000Z',
            'cart_item_ids' => ['cart-1'],
            'total_product' => 10000,
            'total_shipping' => 15000,
            'total_all' => 25000,
        ]];

        $this->assertTrue($service->checkoutSnapshotChanged($backendSnapshot, [
            'alamat_id' => 'address-old',
            'alamat_updated_at' => '2026-07-26T09:00:00.000000Z',
            'cart_item_ids' => ['cart-1'],
            'total_product' => 10000,
            'total_shipping' => 15000,
            'total_all' => 25000,
        ]));
    }

    /**
     * Memastikan penghapusan alamat aktif tidak mengaktifkan alamat manual legacy sebagai fallback.
     *
     * Alamat manual tetap tersimpan agar buyer dapat memperbaruinya, tetapi tidak boleh menjadi
     * alamat checkout aktif sampai lokasinya diverifikasi kembali melalui Pinpoint.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function deleting_the_active_address_does_not_enable_a_legacy_manual_fallback(): void
    {
        $activeAddress = Alamat::create(array_merge([
            'user_id' => $this->buyer->id,
            'type' => 'buyer',
            'enable' => true,
        ], $this->buyerFields(), $this->mapFields()));
        $manualAddress = Alamat::create([
            'user_id' => $this->buyer->id,
            'type' => 'buyer',
            'place' => 'Alamat Lama',
            'alamat' => 'Alamat manual legacy',
            'location_source' => 'manual',
            'enable' => false,
        ]);

        $this->deleteJson("/api/alamat/buyer/{$activeAddress->id}")
            ->assertOk();

        $this->assertDatabaseHas('alamats', [
            'id' => $manualAddress->id,
            'enable' => false,
        ]);
        $this->assertDatabaseMissing('alamats', [
            'user_id' => $this->buyer->id,
            'type' => 'buyer',
            'enable' => true,
        ]);
    }

    /**
     * Menyusun field penerima dan label alamat buyer yang valid untuk fixture request.
     *
     * Nilai dasar dipisahkan dari metadata pinpoint agar test dapat mengubah koordinat tanpa
     * mengulang data penerima pada setiap skenario.
     *
     * @return array<string, string>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function buyerFields(): array
    {
        return [
            'place' => 'Rumah',
            'name' => 'Penerima Test',
            'phone' => '081234567890',
        ];
    }

    /**
     * Menggabungkan koordinat dan metadata pinpoint default dengan override skenario. Payload ini
     * merepresentasikan field lokasi yang dikirim browser sebelum diverifikasi ulang oleh backend.
     *
     * @param  array<string, mixed>  $overrides  Nilai pengganti yang digabungkan dengan fixture default.
     *
     * @return array<string, mixed>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function mapFields(array $overrides = []): array
    {
        return array_merge([
            'alamat' => '',
            'location_source' => 'map',
            'latitude' => -6.1755043,
            'longitude' => 106.8272634,
            'geoapify_place_id' => 'geoapify-test-place',
            'formatted_address' => 'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
            'address_detail' => 'Blok B No. 12',
        ], $overrides);
    }

    /**
     * Memalsukan response reverse-geocoding Indonesia dengan metadata server yang deterministik.
     * Helper memungkinkan test membuktikan bahwa backend menyimpan hasil provider, bukan nilai alamat
     * yang dikirim client.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    private function fakeIndonesiaVerification(): void
    {
        Http::fake(function () {
            if ($this->verificationStatus !== 200) {
                return Http::response([], $this->verificationStatus);
            }

            return Http::response(['results' => [[
                'country_code' => $this->verificationCountryCode,
                'formatted' => 'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
                'place_id' => 'verified-place-id',
            ]]], 200);
        });
    }
}
