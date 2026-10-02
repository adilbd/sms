<?php

namespace App\Http\Requests\AdmissionRound;

use App\Http\Requests\AdmissionRound\Concerns\HasAdmissionRoundRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdmissionRoundRequest extends FormRequest
{
    use HasAdmissionRoundRules;

    // Access is enforced by the permission middleware in AdmissionRoundController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->roundRules('sometimes');
    }
}
