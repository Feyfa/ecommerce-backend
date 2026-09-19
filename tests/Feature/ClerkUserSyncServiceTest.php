<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Clerk\ClerkUserSyncService;
use Clerk\Backend\Models\Components\EmailAddress;
use Clerk\Backend\Models\Components\EmailAddressObject;
use Clerk\Backend\Models\Components\User as ClerkUser;
use Clerk\Backend\Models\Components\UserObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi kontrak sinkronisasi identity Clerk ke model user lokal.
 */
class ClerkUserSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Memastikan identity Clerk baru menghasilkan user lokal non-null dan status create.
     *
     * @return void Tidak mengembalikan nilai; kontrak hasil sinkronisasi diverifikasi melalui assertion.
     */
    public function test_sync_creates_a_new_local_user_with_created_status(): void
    {
        $result = $this->syncService()->syncClerkUserWithStatus(
            $this->clerkUser('user_sync_new', 'new-sync@example.com', 'New', 'User'),
        );

        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertTrue($result['was_created']);
        $this->assertSame('user_sync_new', $result['user']->clerk_user_id);
        $this->assertSame('new-sync@example.com', $result['user']->email);
        $this->assertSame('New User', $result['user']->name);
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * Memastikan email Clerk dapat memasang identity ke user lokal tanpa membuat row duplikat.
     *
     * Pencocokan email tetap case-insensitive dan hasilnya ditandai sebagai user existing.
     *
     * @return void Tidak mengembalikan nilai; identity dan jumlah row diverifikasi melalui assertion.
     */
    public function test_sync_attaches_to_an_existing_user_by_case_insensitive_email(): void
    {
        $existingUser = User::factory()->create([
            'clerk_user_id' => null,
            'email' => 'Existing.Sync@Example.com',
            'name' => 'Existing User',
        ]);

        $result = $this->syncService()->syncClerkUserWithStatus(
            $this->clerkUser('user_sync_existing', 'existing.sync@example.com', 'Synced', 'User'),
        );

        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertFalse($result['was_created']);
        $this->assertSame($existingUser->id, $result['user']->id);
        $this->assertSame('user_sync_existing', $result['user']->clerk_user_id);
        $this->assertSame('existing.sync@example.com', $result['user']->email);
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * Memastikan kegagalan reload user menghasilkan exception terkontrol dan rollback transaksi.
     *
     * Listener test menghapus row tepat setelah save agar `fresh()` tidak dapat memuatnya kembali.
     * Transaction harus memulihkan data user existing beserta identity sebelum sinkronisasi.
     *
     * @return void Tidak mengembalikan nilai; exception dan state database diverifikasi melalui assertion.
     */
    public function test_sync_rolls_back_when_saved_user_cannot_be_reloaded(): void
    {
        $email = 'reload-failure@example.com';
        $existingUser = User::factory()->create([
            'clerk_user_id' => 'user_reload_failure',
            'email' => $email,
            'name' => 'Name Before Sync',
        ]);

        User::saved(static function (User $user) use ($email): void {
            if ($user->email !== $email) {
                return;
            }

            $user->newQuery()
                ->whereKey($user->getKey())
                ->delete();
        });

        try {
            $this->syncService()->syncClerkUserWithStatus(
                $this->clerkUser('user_reload_failure', $email, 'Name', 'After Sync'),
            );
            $this->fail('The sync should fail when the saved user cannot be reloaded.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synced local user could not be reloaded.', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'clerk_user_id' => 'user_reload_failure',
            'email' => $email,
            'name' => 'Name Before Sync',
        ]);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    /**
     * Mengambil service sinkronisasi Clerk dari container aplikasi.
     *
     * @return ClerkUserSyncService Service yang menggunakan dependency aplikasi sebenarnya.
     */
    private function syncService(): ClerkUserSyncService
    {
        return $this->app->make(ClerkUserSyncService::class);
    }

    /**
     * Membuat model Clerk minimum dengan identity yang dapat dikontrol oleh test.
     *
     * @param  string  $clerkUserId  ID identity Clerk yang akan disinkronkan.
     * @param  string  $email  Email utama untuk pembuatan atau pencocokan user lokal.
     * @param  string  $firstName  Nama depan untuk membentuk nama tampilan user.
     * @param  string  $lastName  Nama belakang untuk membentuk nama tampilan user.
     *
     * @return ClerkUser Model Clerk sintetis yang tidak melakukan request eksternal.
     */
    private function clerkUser(
        string $clerkUserId,
        string $email,
        string $firstName,
        string $lastName,
    ): ClerkUser {
        $emailAddressId = 'idn_'.$clerkUserId;
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
