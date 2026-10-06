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
    ['method' => 'POST', 'uri' => 'api/v1/patients', 'cus' => 'CUS-14', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}', 'cus' => 'CUS-21', 'denied' => ['super_admin']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}/representatives', 'cus' => 'CUS-16', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/patients/{patient}/representatives', 'cus' => 'CUS-16', 'denied' => ['super_admin', 'dentist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/patients/{patient}/representatives/{representative}/end', 'cus' => 'CUS-16', 'denied' => ['super_admin', 'dentist', 'patient']],

    // Fontanería (DI-16): la autorización es la firma de 10 minutos emitida tras la Policy.
    ['method' => 'GET', 'uri' => 'api/v1/files/{tenant}/{file}', 'cus' => 'DI-16', 'denied' => []],
];
