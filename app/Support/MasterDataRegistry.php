<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MasterData\AccountType;
use App\Models\MasterData\Bank;
use App\Models\MasterData\ContractType;
use App\Models\MasterData\CustomerType;
use App\Models\MasterData\DocumentType;
use App\Models\MasterData\Employer;
use App\Models\MasterData\EmploymentType;
use App\Models\MasterData\IdType;
use App\Models\MasterData\LoanType;
use App\Models\MasterData\MaritalStatusOption;
use App\Models\MasterData\MasterDataModel;
use App\Models\MasterData\MobileMoneyProvider;
use App\Models\MasterData\Occupation;
use App\Models\MasterData\Sector;
use App\Models\MasterData\SectorCategory;
use App\Models\MasterData\WorkType;

/**
 * Which admin-managed list a slug names.
 *
 * This map lived inside MasterDataController as a private constant, which was
 * right while the controller was the only thing that needed it. A category's
 * registration field may now declare `dataSource: "marital-statuses"`, and the
 * validator has to resolve that slug to decide whether a submitted value is
 * one of the list's entries — so two callers need the same answer and it must
 * not be written down twice. The controller now reads it from here.
 *
 * `sector-categories` is in SOURCES but not in LISTS, and that difference is
 * load-bearing: it is a valid data source for a registration field, and it is
 * NOT a list the generic index/store/update routes can serve, because its rows
 * belong to a parent sector and returning every cadre of every employing body
 * to a form that has already chosen one is the mistake the address lookups
 * avoid.
 */
final class MasterDataRegistry
{
    /**
     * The flat lists, servable whole. Keyed by the slug that appears in
     * `/api/v1/master-data/{list}`.
     *
     * @var array<string, class-string<MasterDataModel>>
     */
    public const array LISTS = [
        'loan-types' => LoanType::class,
        'customer-types' => CustomerType::class,
        'account-types' => AccountType::class,
        'work-types' => WorkType::class,
        'employment-types' => EmploymentType::class,
        'occupations' => Occupation::class,
        'banks' => Bank::class,
        'mobile-money-providers' => MobileMoneyProvider::class,
        'marital-statuses' => MaritalStatusOption::class,
        /* KYC document types — what a category's required_documents names. */
        'document-types' => DocumentType::class,
        /* Which identity document was seen, and on what terms somebody is
           employed — see the 2026_08_30 migrations. */
        'id-types' => IdType::class,
        'contract-types' => ContractType::class,
        /* The employing body. Its cadres are NOT here: see the note above. */
        'sectors' => Sector::class,
        /* Private companies. A SEPARATE list from `sectors`. */
        'employers' => Employer::class,
    ];

    /** The slug for the one list that has a parent. */
    public const string SECTOR_CATEGORIES = 'sector-categories';

    /**
     * Which column a parented source filters on.
     *
     * One entry today, and the mechanism is general because the requirement is:
     * a dependent dropdown must reload from its parent without anybody editing
     * a component. A second parented list adds a row here and nothing else.
     *
     * @var array<string, string>
     */
    public const array PARENT_COLUMNS = [
        self::SECTOR_CATEGORIES => 'sector_id',
    ];

    /** The column a source filters on when it depends on another field, or null. */
    public static function parentColumn(string $source): ?string
    {
        return self::PARENT_COLUMNS[$source] ?? null;
    }

    /**
     * Every slug a registration field may name as its data source.
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        return [...array_keys(self::LISTS), self::SECTOR_CATEGORIES];
    }

    /**
     * The model behind a data source, or null when the slug names nothing.
     *
     * Null rather than an exception: a category configured against a list that
     * was later removed from the application should make one field stop
     * constraining its values, not make every registration under that category
     * fail.
     *
     * @return class-string<MasterDataModel>|null
     */
    public static function model(string $source): ?string
    {
        if ($source === self::SECTOR_CATEGORIES) {
            return SectorCategory::class;
        }

        return self::LISTS[$source] ?? null;
    }
}
