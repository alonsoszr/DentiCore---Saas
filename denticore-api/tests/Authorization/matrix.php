<?php

/*
 * AUTH_MATRIX (SDD §3.4, §3.8; RF-004, RN-06): transcripción de las celdas ❌ de la matriz
 * permiso × rol para cada ruta registrada. Cada ruta de routes/api figura aquí; `denied`
 * lista los roles que deben recibir 403 (o 404 si no pueden ver el registro).
 *
 * Roles: super_admin (SA), clinic_admin (CA), dentist (OD), receptionist (RE), patient (PA).
 * La columna REP (representante) se agrega con el portal (TASK-090).
 *
 * Rutas heredadas de las fases 0–3 con el CUS de su ruta equivalente del SDD:
 * /tenants → /platform/tenants (CUS-01, CUS-02).
 */

return [
    ['method' => 'POST', 'uri' => 'api/v1/auth/login', 'cus' => 'CUS-06', 'denied' => []],
    ['method' => 'POST', 'uri' => 'api/v1/auth/logout', 'cus' => 'CUS-10', 'denied' => []],
    ['method' => 'GET', 'uri' => 'api/v1/auth/me', 'cus' => 'CUS-78', 'denied' => []],

    ['method' => 'GET', 'uri' => 'api/v1/tenants', 'cus' => 'CUS-01', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/tenants', 'cus' => 'CUS-01', 'denied' => ['clinic_admin', 'dentist', 'receptionist', 'patient']],

    ['method' => 'GET', 'uri' => 'api/v1/users', 'cus' => 'CUS-11', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/users', 'cus' => 'CUS-11', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],
    ['method' => 'PATCH', 'uri' => 'api/v1/users/{user}', 'cus' => 'CUS-11', 'denied' => ['super_admin', 'dentist', 'receptionist', 'patient']],

    ['method' => 'GET', 'uri' => 'api/v1/patients', 'cus' => 'CUS-13', 'denied' => ['super_admin', 'patient']],
    ['method' => 'POST', 'uri' => 'api/v1/patients', 'cus' => 'CUS-14', 'denied' => ['super_admin', 'patient']],
    ['method' => 'GET', 'uri' => 'api/v1/patients/{patient}', 'cus' => 'CUS-21', 'denied' => ['super_admin']],

    // Fontanería (DI-16): la autorización es la firma de 10 minutos emitida tras la Policy.
    ['method' => 'GET', 'uri' => 'api/v1/files/{tenant}/{file}', 'cus' => 'DI-16', 'denied' => []],
];
