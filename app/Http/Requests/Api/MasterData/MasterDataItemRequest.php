<?php

namespace App\Http\Requests\Api\MasterData;

use App\Services\Customers\MasterDataRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create / update a master-data row (Settings → Master Data). Requires settings.manage, checked before validation.
 * The code is unique within the parent for child lists; left blank it is derived from the name.
 */
class MasterDataItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settings.manage');
    }

    protected function prepareForValidation(): void
    {
        $code = is_string($this->input('code')) ? trim($this->input('code')) : '';
        if ($code === '' && is_string($this->input('name')) && trim($this->input('name')) !== '') {
            $code = MasterDataRegistry::codeFor($this->input('name'));
        }

        $this->merge(['code' => $code === '' ? null : $code]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $registry = app(MasterDataRegistry::class);
        $slug = (string) $this->route('list');
        $class = $registry->modelClass($slug);
        $table = (new $class)->getTable();
        $parentColumn = $registry->parentColumn($slug);
        $id = $this->route('id');

        // On create a soft-deleted row with the same code is restored; on update any row (even deleted) conflicts.
        $unique = Rule::unique($table, 'code')->ignore($id);
        if ($id === null) {
            $unique->whereNull('deleted_at');
        }
        if ($parentColumn !== null) {
            $unique->where($parentColumn, (int) $this->input('parentId'));
        }

        return [
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.\-]+$/', $unique],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'isActive' => ['sometimes', 'boolean'],
            ...($parentColumn === null ? [] : [
                'parentId' => ['required', 'integer', Rule::exists((new ($registry->modelClass($registry->parentSlug($slug))))->getTable(), 'id')->whereNull('deleted_at')],
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.unique' => 'That code is already used in this list.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function itemData(): array
    {
        $parentColumn = app(MasterDataRegistry::class)->parentColumn((string) $this->route('list'));

        return [
            'code' => $this->string('code')->toString(),
            'name' => $this->string('name')->trim()->toString(),
            'description' => $this->filled('description') ? $this->string('description')->trim()->toString() : null,
            'sort_order' => $this->integer('sortOrder'),
            'is_active' => $this->has('isActive') ? $this->boolean('isActive') : true,
            ...($parentColumn === null ? [] : [$parentColumn => $this->integer('parentId')]),
        ];
    }
}
