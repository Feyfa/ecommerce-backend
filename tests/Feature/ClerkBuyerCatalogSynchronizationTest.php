<?php

namespace Tests\Feature;

use App\Enums\OutboxAggregateType;
use App\Enums\OutboxEventType;
use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Clerk\ClerkUserSyncService;
use App\Services\OutboxRecorderService;
use Clerk\Backend\Models\Components\EmailAddress;
use Clerk\Backend\Models\Components\EmailAddressObject;
use Clerk\Backend\Models\Components\User as ClerkUser;
use Clerk\Backend\Models\Components\UserObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi perubahan fallback nama seller dari Clerk mengantrekan proyeksi ulang katalog buyer.
 */
class ClerkBuyerCatalogSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Memastikan perubahan nama mencatat satu outbox seller-wide dan sync identik tidak mencatat ulang.
     *
     * @return void Tidak mengembalikan nilai; kegagalan kontrak dinyatakan melalui assertion.
     */
    public function test_clerk_name_change_dispatches_once_and_unchanged_sync_does_not(): void
    {
        $clerkUserId = 'user_buyer_catalog_sync';
        $email = 'catalog-sync@example.com';
        $seller = User::factory()->create([
            'clerk_user_id' => $clerkUserId,
            'email' => $email,
            'name' => 'Nama Seller Lama',
        ]);
        $syncService = $this->app->make(ClerkUserSyncService::class);
        $clerkUser = $this->clerkUser($clerkUserId, $email, 'Nama', 'Seller Baru');

        $firstSync = $syncService->syncClerkUserWithStatus($clerkUser);

        $this->assertSame('Nama Seller Baru', $firstSync['user']->name);
        $outbox = OutboxMessage::query()->sole();
        $this->assertSame(OutboxEventType::BUYER_CATALOG_SELLER_SYNC->value, $outbox->event_type);
        $this->assertSame(OutboxAggregateType::SELLER->value, $outbox->aggregate_type);
        $this->assertSame($seller->id, $outbox->aggregate_id);
        $this->assertSame(OutboxStatus::PENDING, $outbox->status);
        $this->assertEquals([
            'schema_version' => 1,
            'source' => OutboxRecorderService::SOURCE_CLERK_NAME_CHANGED,
        ], $outbox->payload);
        Queue::assertNothingPushed();

        $secondSync = $syncService->syncClerkUserWithStatus($clerkUser);

        $this->assertSame('Nama Seller Baru', $secondSync['user']->name);
        $this->assertDatabaseCount('outbox_messages', 1);
        Queue::assertNothingPushed();
    }

    /**
     * Memastikan kegagalan callback transaksi Clerk membatalkan perubahan nama dan outbox bersama.
     *
     * @return void Tidak mengembalikan nilai; rollback user dan outbox diverifikasi melalui assertion.
     */
    public function test_clerk_transaction_failure_rolls_back_name_and_outbox_together(): void
    {
        $clerkUserId = 'user_buyer_catalog_sync_rollback';
        $email = 'catalog-sync-rollback@example.com';
        $seller = User::factory()->create([
            'clerk_user_id' => $clerkUserId,
            'email' => $email,
            'name' => 'Nama Seller Sebelum Rollback',
        ]);
        $syncService = $this->app->make(ClerkUserSyncService::class);

        try {
            $syncService->syncClerkUserWithStatus(
                $this->clerkUser($clerkUserId, $email, 'Nama', 'Seller Harus Rollback'),
                static function (): void {
                    throw new RuntimeException('Clerk transaction callback failed.');
                },
            );
            $this->fail('The Clerk transaction should propagate the callback failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Clerk transaction callback failed.', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', [
            'id' => $seller->id,
            'name' => 'Nama Seller Sebelum Rollback',
        ]);
        $this->assertDatabaseCount('outbox_messages', 0);
        Queue::assertNothingPushed();
    }

    /**
     * Membuat model Clerk minimum dengan identity dan nama yang dapat dikontrol oleh test.
     *
     * @param  string  $clerkUserId  ID user Clerk yang akan disinkronkan ke seller lokal.
     * @param  string  $email  Email utama yang menjadi identity pencocokan user lokal.
     * @param  string  $firstName  Nama depan yang digunakan untuk membentuk fallback nama seller.
     * @param  string  $lastName  Nama belakang yang digunakan untuk membentuk fallback nama seller.
     *
     * @return ClerkUser Model Clerk sintetis untuk menjalankan sinkronisasi tanpa request eksternal.
     */
    private function clerkUser(
        string $clerkUserId,
        string $email,
        string $firstName,
        string $lastName,
    ): ClerkUser {
        $emailAddressId = 'idn_primary_email';
        $emailAddress = new EmailAddress(
            object: EmailAddressObject::EmailAddress,
            emailAddress: $email,
            reserved: false,
            linkedTo: [],
            createdAt: 1,
            updatedAt: 1,
            id: $emailAddressId,
        );

        return new ClerkUser(
            id: $clerkUserId,
            object: UserObject::User,
            hasImage: false,
            publicMetadata: [],
            emailAddresses: [$emailAddress],
            phoneNumbers: [],
            web3Wallets: [],
            passkeys: [],
            passwordEnabled: true,
            twoFactorEnabled: false,
            totpEnabled: false,
            backupCodeEnabled: false,
            externalAccounts: [],
            samlAccounts: [],
            enterpriseAccounts: [],
            banned: false,
            locked: false,
            updatedAt: 1,
            createdAt: 1,
            deleteSelfEnabled: true,
            createOrganizationEnabled: true,
            primaryEmailAddressId: $emailAddressId,
            firstName: $firstName,
            lastName: $lastName,
        );
    }
}
