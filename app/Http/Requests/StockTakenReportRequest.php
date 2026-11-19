<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockTakenReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stock_category' => [
                'required',
                Rule::in(Category::getItemCategories()->keys()->prepend('all')->all()),
            ],
            'start_date' => [
                'required',
                'date',
            ],
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
            ],
        ];
    }
}
