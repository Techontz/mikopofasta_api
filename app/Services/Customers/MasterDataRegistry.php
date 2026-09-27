<?php

namespace App\Services\Customers;

use App\Models\MasterData\Bank;
use App\Models\MasterData\BusinessSector;
use App\Models\MasterData\BusinessType;
use App\Models\MasterData\College;
use App\Models\MasterData\Course;
use App\Models\MasterData\DocumentType;
use App\Models\MasterData\GovernmentBody;
use App\Models\MasterData\GovernmentCadre;
use App\Models\MasterData\GovernmentDepartment;
use App\Models\MasterData\IdType;
use App\Models\MasterData\MaritalStatus;
use App\Models\MasterData\MasterDataModel;
use App\Models\MasterData\MobileMoneyProvider;
use App\Models\MasterData\PensionFund;
use App\Models\MasterData\PrivateCadre;
use App\Models\MasterData\PrivateDepartment;
use App\Models\MasterData\PrivateEmployer;
use App\Models\MasterData\PrivateSector;
use Illuminate\Support\Str;

/**
 * Master-data API slugs (kebab-case plurals) mapped to their models and, for parented lists, to the parent
 * column and parent slug (CUSTOMER_MODULE_IMPLEMENTATION.md §1.3, CUSTOMER_TYPE_REQUIREMENTS.md §8).
 */
class MasterDataRegistry
{
    /**
     * Flat lists: slug => model.
     *
     * @var array<string, class-string<MasterDataModel>>
     */
    public const FLAT = [
        'banks' => Bank::class,
        'mobile-money-providers' => MobileMoneyProvider::class,
        'marital-statuses' => MaritalStatus::class,
        'id-types' => IdType::class,
        'document-types' => DocumentType::class,
        'government-bodies' => GovernmentBody::class,
        'private-sectors' => PrivateSector::class,
        'business-sectors' => BusinessSector::class,
        'colleges' => College::class,
        'pension-funds' => PensionFund::class,
    ];

    /**
     * Parented lists: slug => [model, parent slug].
     *
     * @var array<string, array{0: class-string<MasterDataModel>, 1: string}>
     */
    public const PARENTED = [
        'government-departments' => [GovernmentDepartment::class, 'government-bodies'],
        'government-cadres' => [GovernmentCadre::class, 'government-departments'],
        'private-employers' => [PrivateEmployer::class, 'private-sectors'],
        'private-departments' => [PrivateDepartment::class, 'private-sectors'],
        'private-cadres' => [PrivateCadre::class, 'private-departments'],
        'business-types' => [BusinessType::class, 'business-sectors'],
        'courses' => [Course::class, 'colleges'],
    ];

    /**
     * Deterministic code for a master-data name: the upper snake case slug (at most 80 characters).
     */
    public static function codeFor(string $name): string
    {
        $code = Str::upper(Str::slug($name, '_'));

        return Str::limit($code === '' ? md5($name) : $code, 80, '');
    }

    /**
     * @return list<string>
     */
    public function flatSlugs(): array
    {
        return array_keys(self::FLAT);
    }

    /**
     * @return list<string>
     */
    public function parentedSlugs(): array
    {
        return array_keys(self::PARENTED);
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return [...$this->flatSlugs(), ...$this->parentedSlugs()];
    }

    public function has(string $slug): bool
    {
        return isset(self::FLAT[$slug]) || isset(self::PARENTED[$slug]);
    }

    public function isParented(string $slug): bool
    {
        return isset(self::PARENTED[$slug]);
    }

    /**
     * @return class-string<MasterDataModel>|null
     */
    public function modelClass(string $slug): ?string
    {
        return self::FLAT[$slug] ?? self::PARENTED[$slug][0] ?? null;
    }

    /**
     * The foreign key to the parent list (e.g. government_body_id), or null for a flat or unknown list.
     */
    public function parentColumn(string $slug): ?string
    {
        return $this->isParented($slug) ? self::PARENTED[$slug][0]::PARENT_COLUMN : null;
    }

    /**
     * The parent list's slug (e.g. government-bodies), or null for a flat or unknown list.
     */
    public function parentSlug(string $slug): ?string
    {
        return self::PARENTED[$slug][1] ?? null;
    }

    /**
     * Slug of a model class, or null when the class is not a registered list.
     */
    public function slugFor(string $modelClass): ?string
    {
        foreach (self::FLAT as $slug => $class) {
            if ($class === $modelClass) {
                return $slug;
            }
        }
        foreach (self::PARENTED as $slug => [$class]) {
            if ($class === $modelClass) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Find a (not soft-deleted) row of a list by id. Returns null for an unknown list, a non-integer id or a missing row.
     */
    public function find(string $slug, mixed $id): ?MasterDataModel
    {
        $class = $this->modelClass($slug);
        if ($class === null || ! $this->isIntegerId($id)) {
            return null;
        }

        return $class::query()->find((int) $id);
    }

    private function isIntegerId(mixed $id): bool
    {
        return is_int($id) || (is_string($id) && ctype_digit(trim($id)) && trim($id) !== '');
    }
}
