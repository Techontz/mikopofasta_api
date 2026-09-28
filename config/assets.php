<?php

/*
|--------------------------------------------------------------------------
| Asset capital contributions & Asset Registry
|--------------------------------------------------------------------------
|
| The single source of the asset form: every asset type with its type-specific fields (and which are required), whether
| quantity is fixed to 1, whether condition applies, the suggested documents and the fixed-asset ledger account
| (an App\Enums\Account value — never a database id). The API validates from this file and the web form renders from
| GET /capital/assets/config, so field lists are never duplicated.
|
| Field: key, label, type (text|number|integer|select|textarea), required, options (select), identifier (shown on
| the label / scan page and searchable), min/max (numbers).
|
*/

$sizeUnits = ['sqm' => 'Square metres (sqm)', 'acres' => 'Acres', 'hectares' => 'Hectares'];
$year = (int) date('Y') + 1;

return [

    /* Web app base URL encoded in asset QR codes: {frontend_url}/capital/assets/scan/{qr_token}. */
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    'code_prefix' => 'AST-',

    'document_max_kb' => 10240,
    'document_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],

    'conditions' => ['new' => 'New', 'used' => 'Used', 'refurbished' => 'Refurbished'],

    'valuation_methods' => [
        'agreed_value' => 'Agreed Value',
        'market_valuation' => 'Market Valuation',
        'professional_valuation' => 'Professional Valuation',
        'purchase_value' => 'Purchase Value',
        'other' => 'Other',
    ],

    /* Lasting statuses. A branch transfer is an event, not a status; `reversed` is set only by a contribution reversal. */
    'statuses' => [
        'active' => 'Active',
        'in_use' => 'In Use',
        'available' => 'Available',
        'under_maintenance' => 'Under Maintenance',
        'disposed' => 'Disposed',
        'written_off' => 'Written Off',
    ],

    'terminal_statuses' => ['disposed', 'written_off', 'reversed'],

    'types' => [
        'vehicle' => [
            'label' => 'Vehicle',
            'account' => 'motor_vehicles',
            'fixed_quantity' => false,
            'condition' => true,
            'location_required' => false,
            'fields' => [
                ['key' => 'make', 'label' => 'Make', 'type' => 'text', 'required' => true],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'required' => true],
                ['key' => 'year', 'label' => 'Year', 'type' => 'integer', 'required' => true, 'min' => 1950, 'max' => $year],
                ['key' => 'chassis_number', 'label' => 'Chassis Number', 'type' => 'text', 'required' => true, 'identifier' => true],
                ['key' => 'engine_number', 'label' => 'Engine Number', 'type' => 'text', 'required' => false, 'identifier' => true],
                ['key' => 'registration_number', 'label' => 'Registration Number', 'type' => 'text', 'required' => true, 'identifier' => true],
                ['key' => 'mileage', 'label' => 'Mileage (km)', 'type' => 'integer', 'required' => false, 'min' => 0],
            ],
            'documents' => [
                ['key' => 'registration_card', 'label' => 'Registration Card', 'required' => false],
                ['key' => 'insurance', 'label' => 'Insurance', 'required' => false],
                ['key' => 'ownership_document', 'label' => 'Ownership Document', 'required' => false],
                ['key' => 'valuation_document', 'label' => 'Valuation Document', 'required' => false],
                ['key' => 'photo', 'label' => 'Photo', 'required' => false],
            ],
        ],
        'equipment' => [
            'label' => 'Equipment / Machinery',
            'account' => 'equipment',
            'fixed_quantity' => false,
            'condition' => true,
            'location_required' => false,
            'fields' => [
                ['key' => 'manufacturer', 'label' => 'Manufacturer', 'type' => 'text', 'required' => true],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'required' => true],
                ['key' => 'serial_number', 'label' => 'Serial Number', 'type' => 'text', 'required' => true, 'identifier' => true],
            ],
            'documents' => [
                ['key' => 'invoice', 'label' => 'Invoice', 'required' => false],
                ['key' => 'warranty', 'label' => 'Warranty', 'required' => false],
                ['key' => 'serial_documentation', 'label' => 'Serial Documentation', 'required' => false],
                ['key' => 'photo', 'label' => 'Photo', 'required' => false],
            ],
        ],
        'electronics' => [
            'label' => 'Electronics',
            'account' => 'equipment',
            'fixed_quantity' => false,
            'condition' => true,
            'location_required' => false,
            'fields' => [
                ['key' => 'device_type', 'label' => 'Device Type', 'type' => 'text', 'required' => true],
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'required' => true],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'required' => true],
                ['key' => 'serial_number', 'label' => 'Serial Number', 'type' => 'text', 'required' => true, 'identifier' => true],
                ['key' => 'imei', 'label' => 'IMEI', 'type' => 'text', 'required' => false, 'identifier' => true],
            ],
            'documents' => [
                ['key' => 'invoice', 'label' => 'Invoice', 'required' => false],
                ['key' => 'warranty', 'label' => 'Warranty', 'required' => false],
                ['key' => 'serial_documentation', 'label' => 'Serial Documentation', 'required' => false],
                ['key' => 'photo', 'label' => 'Photo', 'required' => false],
            ],
        ],
        'property' => [
            'label' => 'Property / Building',
            'account' => 'buildings',
            'fixed_quantity' => true,
            'condition' => true,
            'location_required' => true,
            'fields' => [
                ['key' => 'property_type', 'label' => 'Property Type', 'type' => 'text', 'required' => true],
                ['key' => 'address', 'label' => 'Address', 'type' => 'text', 'required' => true],
                ['key' => 'plot_title_number', 'label' => 'Plot / Title Number', 'type' => 'text', 'required' => true, 'identifier' => true],
                ['key' => 'size', 'label' => 'Size', 'type' => 'number', 'required' => true, 'min' => 0.01],
                ['key' => 'size_unit', 'label' => 'Size Unit', 'type' => 'select', 'required' => true, 'options' => $sizeUnits],
            ],
            'documents' => [
                ['key' => 'title_deed', 'label' => 'Title Deed', 'required' => false],
                ['key' => 'valuation_report', 'label' => 'Valuation Report', 'required' => false],
                ['key' => 'ownership_document', 'label' => 'Ownership Document', 'required' => false],
                ['key' => 'photo', 'label' => 'Photo', 'required' => false],
            ],
        ],
        'land' => [
            'label' => 'Land',
            'account' => 'land',
            'fixed_quantity' => true,
            'condition' => false,
            'location_required' => true,
            'fields' => [
                ['key' => 'plot_number', 'label' => 'Plot Number', 'type' => 'text', 'required' => true, 'identifier' => true],
                ['key' => 'title_number', 'label' => 'Title Number', 'type' => 'text', 'required' => false, 'identifier' => true],
                ['key' => 'size', 'label' => 'Size', 'type' => 'number', 'required' => true, 'min' => 0.01],
                ['key' => 'size_unit', 'label' => 'Size Unit', 'type' => 'select', 'required' => true, 'options' => $sizeUnits],
            ],
            'documents' => [
                ['key' => 'title_deed', 'label' => 'Title Deed', 'required' => false],
                ['key' => 'valuation_report', 'label' => 'Valuation Report', 'required' => false],
                ['key' => 'ownership_document', 'label' => 'Ownership Document', 'required' => false],
                ['key' => 'photo', 'label' => 'Photo', 'required' => false],
            ],
        ],
        'furniture' => [
            'label' => 'Furniture / General',
            'account' => 'furniture_fixtures',
            'fixed_quantity' => false,
            'condition' => true,
            'location_required' => false,
            'fields' => [
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'text', 'required' => false],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'required' => false],
                ['key' => 'serial_number', 'label' => 'Serial Number', 'type' => 'text', 'required' => false, 'identifier' => true],
            ],
            'documents' => [
                ['key' => 'photo', 'label' => 'Photo', 'required' => false],
                ['key' => 'valuation_document', 'label' => 'Valuation Document', 'required' => false],
            ],
        ],
        'other' => [
            'label' => 'Other',
            'account' => 'other_fixed_assets',
            'fixed_quantity' => false,
            'condition' => true,
            'location_required' => false,
            'fields' => [
                ['key' => 'brand', 'label' => 'Brand / Manufacturer', 'type' => 'text', 'required' => false],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'required' => false],
                ['key' => 'serial_number', 'label' => 'Serial Number', 'type' => 'text', 'required' => false, 'identifier' => true],
                ['key' => 'specification', 'label' => 'Custom Specification', 'type' => 'textarea', 'required' => true],
            ],
            'documents' => [
                ['key' => 'photo', 'label' => 'Photo', 'required' => false],
                ['key' => 'valuation_document', 'label' => 'Valuation Document', 'required' => false],
            ],
        ],
    ],
];
