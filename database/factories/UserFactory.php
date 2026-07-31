<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The password every factory-made user has, so tests can sign in.
     */
    public const string PASSWORD = 'password';

    /**
     * Hashing is the slowest part of creating a user, and tests create a lot
     * of them. One hash is computed and reused for every factory instance.
     */
    protected static ?string $password = null;

    /**
     * Defaults to the least-privileged role. A test that wants authority has
     * to ask for it explicitly — which keeps permission assertions honest.
     *
     * @return array<model-property<User>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => (string) fake()->unique()->numerify('07########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'role_id' => fn (): int => $this->roleId(RoleName::Teller),
            'branch_id' => null,
            'zone_id' => null,
            'region_id' => null,
            'status' => UserStatus::Active,
            'last_login_at' => null,
            'created_by' => null,
        ];
    }

    public function role(RoleName $role): static
    {
        return $this->state(fn (): array => ['role_id' => $this->roleId($role)]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Suspended]);
    }

    public function withoutEmail(): static
    {
        return $this->state(fn (): array => ['email' => null]);
    }

    /**
     * Roles are seeded reference data, not something a factory should invent —
     * creating one on the fly would produce a role with no permissions and a
     * name outside RoleName.
     */
    private function roleId(RoleName $role): int
    {
        $id = Role::query()->where('name', $role->value)->value('id');

        if ($id === null) {
            throw new RuntimeException(
                "Role [{$role->value}] is not seeded. Call seed(RoleSeeder::class) — or use the ".
                'seeded database — before creating users in a test.',
            );
        }

        return (int) $id;
    }
}
