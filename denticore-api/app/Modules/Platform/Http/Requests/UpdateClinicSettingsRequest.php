<?php

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parámetros de la clínica (CUS-04): los límites son los CHECK de `clinic_settings` (SDD §2.3;
 * RN-31, RN-35, RN-49) y de RF-024. `ai_enabled` (RF-027) se acepta desde MS-12 y
 * `complaints_book_url` (RNF-164) desde MS-13.
 */
class UpdateClinicSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:150'],
            'address' => ['sometimes', 'string', 'min:5', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:180'],
            'prices_include_igv' => ['sometimes', 'boolean'],
            'discount_cap_pct' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'budget_validity_days' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'portal_cancel_hours' => ['sometimes', 'integer', 'min:0', 'max:72'],
            'self_booking_enabled' => ['sometimes', 'boolean'],
            'budget_terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'ai_enabled' => ['prohibited'],
            'complaints_book_url' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre comercial',
            'address' => 'dirección',
            'phone' => 'teléfono',
            'contact_email' => 'correo de contacto',
            'prices_include_igv' => 'precios con IGV',
            'discount_cap_pct' => 'tope de descuento',
            'budget_validity_days' => 'vigencia del presupuesto',
            'portal_cancel_hours' => 'plazo de cancelación desde el portal',
            'self_booking_enabled' => 'autoagendamiento',
            'budget_terms' => 'condiciones del presupuesto',
        ];
    }
}
