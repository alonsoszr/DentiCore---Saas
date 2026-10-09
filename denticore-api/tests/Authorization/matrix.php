<?php

/*
 * AUTH_MATRIX (SDD §3.4, §3.8; RF-004, RN-06): transcripción de las celdas ❌ de la matriz
 * permiso × rol para cada ruta registrada. Cada ruta de routes/api figura aquí; `denied`
 * lista los roles que deben recibir 403 (o 404 si no pueden ver el registro).
 *
 * Roles: super_admin (SA), clinic_admin (CA), dentist (OD), receptionist (RE), patient (PA).
 * La columna REP (representante) se agrega con el portal (TASK-090).
 */

return [
    ['method' => 'POST', 'uri' => 'api/v1/auth/login', 'cus' => 'CUS-06', 'denied' => []],
    ['method' => 'POST', 'uri' => 'api/v1/auth/logout', 'cus' => 'CUS-10', 'denied' => []],
    ['method' => 'GET', 'uri' => 'api/v1/auth/me', 'cus' => 'CUS-78', 'denied' => []],
    ['method' => 'POST', 'uri' => 'api/v1/auth/keepalive', 'cus' => 'CUS-06', 'denied' => []],
    ['method' => 'POST', 'uri' => 'api/v1/auth/2fa/verify', 'cus' => 'CUS-07', 'denied' => []],
    ['method' => 'POST', 'uri' => 'api/v1/auth/2fa/setup', 'cus' => 'CUS-08', 'denied' => []],
    ['method' => 'POST', 'uri' => 'api/v1/auth/2fa/confirm', 'cus' => 'CUS-08', 'denied' => []],
    ['method' => 'GET', 'uri' => 'api/v1/public/clinics/{slug}', 'cus' => 'CUS-06', 'denied' => []],
    ['method' => 'POST', 'uri' => 'api/v1/auth/password/forgot', 'cus' => 'CUS-09', 'denied' => []],
    ['method' => 'POST', 'uri' => 'api/v1/auth/password/reset', 'cus' => 'CUS-09', 'denied' => []],
    ['method' => 'GET', 'uri' => 'api/v1/auth/invitations/{token}', 'cus' => 'CUS-01', 'denied' => []],
    ['method' => 'POST', 'uri' => 'api/v1/auth/invitations/{token}/accept', 'cus' => 'CUS-01', 'denied' => []],

    ['method' => 'GET', 'uri' => 'api/v1/platform/plans', 'cus' => 'CUS-03', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/platform/tenants', 'cus' => 'CUS-01', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/platform/tenants', 'cus' => 'CUS-01', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/platform/tenants/{tenant}', 'cus' => 'CUS-01', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'PATCH', 'uri' => 'api/v1/platform/tenants/{tenant}', 'cus' => 'CUS-01', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/platform/tenants/{tenant}/admin-invitation', 'cus' => 'CUS-01', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/platform/tenants/{tenant}/suspend', 'cus' => 'CUS-02', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/platform/tenants/{tenant}/reactivate', 'cus' => 'CUS-02', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'PUT', 'uri' => 'api/v1/platform/tenants/{tenant}/plan', 'cus' => 'CUS-03', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],

    ['method' => 'GET', 'uri' => 'api/v1/clinic/settings', 'cus' => 'CUS-04', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'PATCH', 'uri' => 'api/v1/clinic/settings', 'cus' => 'CUS-04', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/clinic/logo', 'cus' => 'CUS-04', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],

    ['method' => 'GET', 'uri' => 'api/v1/users', 'cus' => 'CUS-11', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/users', 'cus' => 'CUS-11', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/users/{user}', 'cus' => 'CUS-11', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'PATCH', 'uri' => 'api/v1/users/{user}', 'cus' => 'CUS-11', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/users/{user}/deactivate', 'cus' => 'CUS-11', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/users/{user}/reactivate', 'cus' => 'CUS-11', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/users/{user}/invitation', 'cus' => 'CUS-11', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],

    ['method' => 'GET', 'uri' => 'api/v1/patients', 'cus' => 'CUS-13', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/lookup', 'cus' => 'CUS-13', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/patients', 'cus' => 'CUS-14', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}', 'cus' => 'CUS-21', 'denied' => ['super_admin']],
    ['method' => 'PATCH', 'uri' => 'api/v1/patients/{patient}', 'cus' => 'CUS-15', 'denied' => ['super_admin', 'dentist', 'patient']],
    ['method' => 'PUT', 'uri' => 'api/v1/patients/{patient}/medical-history', 'cus' => 'CUS-14', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/consents/preview', 'cus' => 'CUS-17', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/patients/{patient}/consents', 'cus' => 'CUS-17', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/consents', 'cus' => 'CUS-17', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/consents/{consent}/certificate', 'cus' => 'CUS-17', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/representatives', 'cus' => 'CUS-16', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/patients/{patient}/representatives', 'cus' => 'CUS-16', 'denied' => ['super_admin', 'dentist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/patients/{patient}/representatives/{representative}/end', 'cus' => 'CUS-16', 'denied' => ['super_admin', 'dentist', 'patient']],

    // M04 (SDD §3.4, §4.3.4). Recepción abre atenciones solo mediante el check-in (CUS-50).
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/clinical-record', 'cus' => 'CUS-21', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/odontogram', 'cus' => 'CUS-21', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/odontogram/initial', 'cus' => 'CUS-21', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/teeth/{tooth}/history', 'cus' => 'CUS-24', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/attentions', 'cus' => 'CUS-21', 'denied' => ['super_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/patients/{patient}/attentions', 'cus' => 'CUS-25', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/attentions/{attention}', 'cus' => 'CUS-21', 'denied' => ['super_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/attentions/{attention}/close', 'cus' => 'CUS-26', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/cie10', 'cus' => 'CUS-80', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'PUT', 'uri' => 'api/v1/attentions/{attention}/note', 'cus' => 'CUS-80', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/attentions/{attention}/diagnoses', 'cus' => 'CUS-80', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'DELETE', 'uri' => 'api/v1/attentions/{attention}/diagnoses/{diagnosis}', 'cus' => 'CUS-80', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/attentions/{attention}/addenda', 'cus' => 'CUS-81', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/finding-catalog', 'cus' => 'CUS-22', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/attentions/{attention}/odontogram-entries', 'cus' => 'CUS-22', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/odontogram-entries/{entry}/corrections', 'cus' => 'CUS-23', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],

    // M05 (SDD §3.4, §4.3.5). CUS-32: el personal consulta el catálogo; solo CA lo modifica.
    ['method' => 'GET', 'uri' => 'api/v1/procedures', 'cus' => 'CUS-32', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/procedures', 'cus' => 'CUS-32', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'PATCH', 'uri' => 'api/v1/procedures/{procedure}', 'cus' => 'CUS-32', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'DELETE', 'uri' => 'api/v1/procedures/{procedure}', 'cus' => 'CUS-32', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    // CUS-33: el personal consulta los planes; solo OD los elabora (TreatmentPlanPolicy@{create,update}).
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/treatment-plans', 'cus' => 'CUS-33', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/patients/{patient}/treatment-plans', 'cus' => 'CUS-33', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/treatment-plans/{plan}', 'cus' => 'CUS-33', 'denied' => ['super_admin', 'patient']],
    ['method' => 'PATCH', 'uri' => 'api/v1/treatment-plans/{plan}', 'cus' => 'CUS-33', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/treatment-plans/{plan}/items', 'cus' => 'CUS-33', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'PATCH', 'uri' => 'api/v1/plan-items/{item}', 'cus' => 'CUS-33', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'DELETE', 'uri' => 'api/v1/plan-items/{item}', 'cus' => 'CUS-33', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/treatment-plans/{plan}/propose', 'cus' => 'CUS-33', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/treatment-plans/{plan}/reopen', 'cus' => 'CUS-33', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    // CUS-34: solo OD (OdontogramEntryPolicy@decideNoTreat).
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/pending-findings', 'cus' => 'CUS-34', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/odontogram-entries/{entry}/no-treat', 'cus' => 'CUS-34', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    // CUS-40: CA y OD (TreatmentPlanPolicy@cancel, PlanItemPolicy@discard).
    ['method' => 'POST', 'uri' => 'api/v1/plan-items/{item}/discard', 'cus' => 'CUS-40', 'denied' => ['super_admin', 'receptionist', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/treatment-plans/{plan}/cancellation-preview', 'cus' => 'CUS-40', 'denied' => ['super_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/treatment-plans/{plan}/cancel', 'cus' => 'CUS-40', 'denied' => ['super_admin', 'receptionist', 'patient']],
    // CUS-35, CUS-36: el personal gestiona y consulta los presupuestos (BudgetPolicy); el tope de
    // descuento por rol (RN-31) lo aplica el servicio. El portal (PA) llega en MS-06.
    ['method' => 'POST', 'uri' => 'api/v1/treatment-plans/{plan}/budgets', 'cus' => 'CUS-35', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/budgets', 'cus' => 'CUS-36', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/budgets/{budget}', 'cus' => 'CUS-36', 'denied' => ['super_admin', 'patient']],
    ['method' => 'PATCH', 'uri' => 'api/v1/budgets/{budget}/lines/{line}', 'cus' => 'CUS-35', 'denied' => ['super_admin', 'patient']],
    ['method' => 'DELETE', 'uri' => 'api/v1/budgets/{budget}', 'cus' => 'CUS-35', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/budgets/{budget}/issue', 'cus' => 'CUS-35', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/budgets/{budget}/corrections', 'cus' => 'CUS-35', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/budgets/{budget}/pdf', 'cus' => 'CUS-36', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/budgets/{budget}/pdf/regenerate', 'cus' => 'CUS-35', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/documents/{document}', 'cus' => 'CUS-36', 'denied' => ['super_admin', 'patient']],
    // CUS-37: la decisión presencial la registran RE y CA (BudgetPolicy@decide); el portal, en MS-06.
    ['method' => 'POST', 'uri' => 'api/v1/budgets/{budget}/decision', 'cus' => 'CUS-37', 'denied' => ['super_admin', 'dentist', 'patient']],
    // CUS-39: solo OD, en su atención abierta (PlanItemPolicy@perform, AttentionPolicy@write).
    ['method' => 'POST', 'uri' => 'api/v1/plan-items/{item}/performed-procedures', 'cus' => 'CUS-39', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/attentions/{attention}/urgent-procedures', 'cus' => 'CUS-39', 'denied' => ['super_admin', 'clinic_admin', 'receptionist', 'patient']],
    // M03 — Consentimiento informado de procedimientos (SDD §2.5, §4.3.3; CUS-82, CUS-83).
    ['method' => 'GET', 'uri' => 'api/v1/informed-consent-templates', 'cus' => 'CUS-82', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/informed-consent-templates', 'cus' => 'CUS-82', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'PUT', 'uri' => 'api/v1/informed-consent-templates/{template}', 'cus' => 'CUS-82', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/informed-consent-templates/{template}/deactivate', 'cus' => 'CUS-82', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/plan-items/{item}/informed-consents/preview', 'cus' => 'CUS-83', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/plan-items/{item}/informed-consents', 'cus' => 'CUS-83', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/informed-consents/{informedConsent}/revoke', 'cus' => 'CUS-83', 'denied' => ['super_admin', 'patient']],

    // Fontanería (DI-16): la autorización es la firma de 10 minutos emitida tras la Policy.
    ['method' => 'GET', 'uri' => 'api/v1/files/{tenant}/{file}', 'cus' => 'DI-16', 'denied' => []],
];
