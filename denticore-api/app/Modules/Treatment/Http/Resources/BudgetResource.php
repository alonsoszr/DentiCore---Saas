<?php

namespace App\Modules\Treatment\Http\Resources;

use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\BudgetLine;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Presupuesto (CUS-35, CUS-36; RF-115, RF-116, RF-118, RF-121): número, estado de SRS §5.5.3,
 * importes como texto decimal (RNF-046), vigencia, odontólogo con COP, líneas con su descuento y
 * estado del PDF. Un borrador no tiene número ni vencimiento; uno decidido muestra la evidencia
 * de la decisión (CA-37.3, RF-122).
 *
 * @mixin Budget
 */
class BudgetResource extends ApiResource
{
    /**
     * Relaciones que usa el recurso.
     */
    public const RELATIONS = ['plan', 'patient', 'correctedBudget', 'dentist', 'pdfDocument', 'decisionBy', 'lines.planItem', 'lines.procedure'];

    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'number' => $this->number,
            /** @var 'borrador'|'emitido'|'aceptado'|'rechazado'|'vencido'|'reemplazado' */
            'status' => $this->status,
            'plan_id' => $this->plan->uuid,
            'patient_id' => $this->patient->uuid,
            'corrects_budget_id' => $this->correctedBudget?->uuid,
            'prices_include_igv' => $this->prices_include_igv,
            'igv_rate' => $this->igv_rate,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'base_amount' => $this->base_amount,
            'igv_amount' => $this->igv_amount,
            'total' => $this->total,
            'validity_days' => $this->validity_days,
            'issued_at' => $this->issued_at,
            'expires_at' => $this->expires_at,
            'terms' => $this->terms_snapshot,
            'dentist' => $this->dentist === null ? null : [
                'id' => $this->dentist->uuid,
                'name' => $this->dentist->name,
                'cop' => $this->dentist->cop_number,
            ],
            'pdf' => $this->pdfDocument === null ? null : [
                /** @var 'pendiente'|'generando'|'listo'|'fallido' */
                'status' => $this->pdfDocument->status,
            ],
            'decision' => $this->decided_at === null ? null : [
                /** @var 'portal'|'presencial'|'enlace' */
                'channel' => $this->decision_channel,
                'by' => $this->decisionBy === null ? null : [
                    'id' => $this->decisionBy->uuid,
                    'name' => $this->decisionBy->name,
                ],
                /** @var 'titular'|'representante' */
                'signer' => $this->decision_signer,
                'decided_at' => $this->decided_at,
                'ip' => $this->decision_ip,
                /** @var 'precio'|'segunda_opinion'|'momento_no_oportuno'|'otro'|null */
                'rejection_reason' => $this->rejection_reason,
                'rejection_detail' => $this->rejection_detail,
                'signed_file' => $this->signed_file_id !== null,
            ],
            'replaced_at' => $this->replaced_at,
            'expired_at' => $this->expired_at,
            'lines' => $this->lines->map(fn (BudgetLine $line) => [
                'id' => $line->uuid,
                'plan_item_id' => $line->planItem->uuid,
                'procedure' => [
                    'id' => $line->procedure->uuid,
                    'code' => $line->procedure->code,
                    'name' => $line->procedure->name,
                ],
                'description' => $line->description,
                'tooth' => $line->tooth,
                /** @var list<'M'|'D'|'O'|'I'|'V'|'L'|'P'> */
                'surfaces' => $line->surfaces,
                'unit_price' => $line->unit_price,
                'quantity' => $line->quantity,
                'discount_pct' => $line->discount_pct,
                'discount_reason' => $line->discount_reason,
                'discount_approved' => $line->discount_approved_by !== null,
                'subtotal' => $line->subtotal,
            ])->values()->all(),
            'created_at' => $this->created_at,
        ];
    }
}
